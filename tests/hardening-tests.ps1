# REGIS-TRACK — Phase 7 hardening test suite
# Covers: absolute session lifetime, per-IP login throttle, restricted-DB-user
# privileges (FR5 audit guarantee) and a full API pass under the least-privilege
# user. Boots its own server with tightened env config.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8098

$MySql = 'C:\xampp\mysql\bin\mysql.exe'
$devAppPassword = 'dev-Registrack1!'

# --- Provision the least-privilege runtime user (idempotent) --------------------
$provisionSql = @"
CREATE USER IF NOT EXISTS 'registrack_app'@'localhost' IDENTIFIED BY '$devAppPassword';
GRANT SELECT, INSERT, UPDATE ON registrack.users TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.document_types TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.requests TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT ON registrack.request_history TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT ON registrack.audit_events TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.notifications TO 'registrack_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON registrack.password_resets TO 'registrack_app'@'localhost';
GRANT SELECT, UPDATE ON registrack.system_settings TO 'registrack_app'@'localhost';
FLUSH PRIVILEGES;
"@
$provisionSql | & $MySql -u root
if ($LASTEXITCODE -ne 0) { throw 'provisioning restricted user failed' }

function Invoke-DbAsApp {
    # runs SQL as the restricted app user; returns exit code.
    # Expected denials write to stderr, which would become terminating errors
    # under ErrorActionPreference=Stop — isolate them here.
    param([string]$Sql)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $Sql | & $MySql -u registrack_app "-p$devAppPassword" registrack 2>&1 | Out-Null
        return $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
}

# --- Tightened env for this server instance --------------------------------------
$env:AUTH_SESSION_MAX_MINUTES = '0.05'          # ~3 seconds absolute lifetime
$env:AUTH_IP_THROTTLE_MAX_FAILURES = '3'        # trip after 3 failures
$env:AUTH_IP_THROTTLE_WINDOW_MINUTES = '10'
$env:DB_USER = 'registrack_app'                 # run the whole API least-privileged
$env:DB_PASSWORD = $devAppPassword

$server = Start-TestServer
try {
    $jarStudent = Join-Path $script:ApiTmp 'student.jar'
    Remove-Item $jarStudent -ErrorAction SilentlyContinue

    # --- Full API pass under the restricted DB user --------------------------------
    $login = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudent `
        -Body '{"email":"student1@tcg.edu.ph","password":"Password123!"}'
    Assert-Status 'login under least-privilege DB user' 200 $login
    $csrf = ((($login.Body | ConvertFrom-Json).data).csrf_token)

    try { $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila') } catch [TimeZoneNotFoundException] {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaToday.AddDays(3).ToString('yyyy-MM-dd')

    $submitted = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrf; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
        -Body ('{"document_type_id":1,"quantity":1,"purpose":"Hardening pass under restricted user","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'submission under least-privilege DB user (atomic writes work)' 201 $submitted

    # --- Absolute session lifetime ----------------------------------------------------
    Start-Sleep -Seconds 4
    $expired = Invoke-Api -Method 'GET' -Path '/api/v1/me' -CookieJar $jarStudent
    Assert-Status 'absolute session lifetime enforced (401)' 401 $expired
    Assert-True 'expiry is the absolute-lifetime variant' `
        ($expired.Body -match 'session_expired')

    # --- Restricted-user privilege matrix (FR5 guarantee) ------------------------------
    Assert-True 'app user CAN read audit trail' `
        ((Invoke-DbAsApp 'SELECT COUNT(*) FROM audit_events;') -eq 0)
    Assert-True 'app user CAN insert audit rows' `
        ((Invoke-DbAsApp "INSERT INTO audit_events (actor_name, actor_role, action, entity) VALUES ('t','guest','test.probe','probe');") -eq 0)
    Assert-True 'app user CANNOT update audit rows (1142 denied)' `
        ((Invoke-DbAsApp "UPDATE audit_events SET actor_name = 'tampered';") -ne 0)
    Assert-True 'app user CANNOT delete audit rows' `
        ((Invoke-DbAsApp 'DELETE FROM audit_events;') -ne 0)
    Assert-True 'app user CANNOT update request history' `
        ((Invoke-DbAsApp "UPDATE request_history SET remark = 'tampered';") -ne 0)
    Assert-True 'app user CANNOT delete request history' `
        ((Invoke-DbAsApp 'DELETE FROM request_history;') -ne 0)
    Assert-True 'app user CANNOT drop tables' `
        ((Invoke-DbAsApp 'DROP TABLE audit_events;') -ne 0)
    Assert-True 'app user CAN still update requests (business need)' `
        ((Invoke-DbAsApp 'UPDATE requests SET current_remark = current_remark WHERE id = 999999;') -eq 0)

    # --- Per-IP login throttle ------------------------------------------------------------
    # Fresh session for a valid login first (throttle counts failures only).
    $jar2 = Join-Path $script:ApiTmp 'student2.jar'
    Remove-Item $jar2 -ErrorAction SilentlyContinue

    for ($i = 1; $i -le 3; $i++) {
        $bad = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
            -Body '{"email":"student1@tcg.edu.ph","password":"WrongPass1"}'
        if ($bad.Status -ne 401) { throw "throttle attempt $i returned $($bad.Status), expected 401" }
    }

    $throttled = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jar2 `
        -Body '{"email":"student1@tcg.edu.ph","password":"Password123!"}'
    Assert-Status 'valid credentials refused once IP throttle trips (429)' 429 $throttled
    Assert-True 'throttle error code is too_many_attempts' `
        ($throttled.Body -match 'too_many_attempts')

    # Cleanup probe row so the audit log stays clean of test noise where possible
    & $MySql -u root -e "USE registrack; DELETE FROM audit_events WHERE action='test.probe';" | Out-Null
} finally {
    Stop-TestServer $server
    Remove-Item Env:AUTH_SESSION_MAX_MINUTES, Env:AUTH_IP_THROTTLE_MAX_FAILURES, Env:AUTH_IP_THROTTLE_WINDOW_MINUTES, Env:DB_USER, Env:DB_PASSWORD -ErrorAction SilentlyContinue
}

Get-ApiTestSummary
