# REGIS-TRACK — Phase 2 API test suite (Auth & RBAC)
# Self-contained: boots a dev server on port 8091, runs assertions via curl.exe,
# prints a PASS/FAIL summary, and exits non-zero on any failure.

param(
    [string]$Port = '8091'
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BaseUrl = "http://127.0.0.1:$Port"
$Tmp = Join-Path $env:TEMP 'registrack-tests'
New-Item -ItemType Directory -Path $Tmp -Force | Out-Null
$BodyFile = Join-Path $Tmp 'last-body.json'

$script:pass = 0
$script:fail = 0

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [string]$Body,
        [string]$CookieJar,
        [hashtable]$Headers
    )
    $curlArgs = @('-s', '-S', '-o', $BodyFile, '-w', '%{http_code}', '-X', $Method, "$BaseUrl$Path")
    if ($CookieJar) { $curlArgs += @('-b', $CookieJar, '-c', $CookieJar) }
    if ($Body) {
        # PS 5.1 quirks: (1) variable names are case-insensitive, so this file
        # must NOT be named like $BodyFile; (2) Set-Content -Encoding UTF8 adds
        # a BOM that breaks json_decode — write BOM-less bytes instead.
        $reqBodyFile = Join-Path $Tmp 'request-body.json'
        [System.IO.File]::WriteAllText($reqBodyFile, $Body, (New-Object System.Text.UTF8Encoding($false)))
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$reqBodyFile")
    }
    if ($Headers) {
        foreach ($key in $Headers.Keys) {
            $curlArgs += @('-H', "$($key): $($Headers[$key])")
        }
    }
    $status = & curl.exe @curlArgs
    $responseBody = ''
    if (Test-Path $BodyFile) { $responseBody = Get-Content $BodyFile -Raw -ErrorAction SilentlyContinue }
    [pscustomobject]@{ Status = [int]($status -as [int]); Body = $responseBody }
}

function Assert-Status {
    param([string]$Name, [int]$Expected, [object]$Result)
    if ($Result.Status -eq $Expected) {
        $script:pass++
        Write-Output "PASS  [$($Result.Status)] $Name"
    } else {
        $script:fail++
        Write-Output "FAIL  [got $($Result.Status), want $Expected] $Name"
        Write-Output "      body: $($Result.Body)"
    }
}

# --- Boot server ---------------------------------------------------------------
$server = Start-Process -FilePath 'php' -ArgumentList '-S', "127.0.0.1:$Port", '-t', 'public' `
    -WorkingDirectory $ProjectRoot -PassThru -WindowStyle Hidden
try {
    $ready = $false
    foreach ($i in 1..20) {
        Start-Sleep -Milliseconds 500
        try {
            $h = Invoke-Api -Method 'GET' -Path '/health'
            if ($h.Status -eq 200) { $ready = $true; break }
        } catch { }
    }
    if (-not $ready) { throw 'Dev server did not become healthy on time.' }
    Write-Output "== server ready on $BaseUrl =="

    $jarAdmin = Join-Path $Tmp 'admin.jar'
    $jarStaff = Join-Path $Tmp 'staff.jar'
    $jarStudent = Join-Path $Tmp 'student.jar'
    Remove-Item $jarAdmin, $jarStaff, $jarStudent -ErrorAction SilentlyContinue

    # --- Health ------------------------------------------------------------------
    Assert-Status 'health endpoint is 200' 200 (Invoke-Api -Method 'GET' -Path '/health')

    # --- Login -------------------------------------------------------------------
    $bad = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body '{"email":"student1@tcg.edu.ph","password":"WrongPassword1"}'
    Assert-Status 'invalid password rejected with generic 401' 401 $bad

    $noBody = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -Body '{}'
    Assert-Status 'missing fields rejected with 422' 422 $noBody

    $studentLogin = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body '{"email":"student1@tcg.edu.ph","password":"Password123!"}' -CookieJar $jarStudent
    Assert-Status 'student login succeeds' 200 $studentLogin
    $studentCsrf = ((($studentLogin.Body | ConvertFrom-Json).data).csrf_token)
    if (-not $studentCsrf) { throw 'student login did not return a CSRF token' }

    $me = Invoke-Api -Method 'GET' -Path '/api/v1/me' -CookieJar $jarStudent
    Assert-Status 'GET /me returns session' 200 $me
    if ((($me.Body | ConvertFrom-Json).data).user.role -ne 'student') { throw '/me did not report student role' }

    # --- RBAC boundaries -----------------------------------------------------------
    Assert-Status 'student blocked from admin user list (403)' 403 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/users' -CookieJar $jarStudent)

    Assert-Status 'anonymous blocked from /me (401)' 401 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/me')

    $staffLogin = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body '{"email":"staff1@tcg.edu.ph","password":"Password123!"}' -CookieJar $jarStaff
    Assert-Status 'staff login succeeds' 200 $staffLogin

    Assert-Status 'staff blocked from admin user list (403)' 403 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/users' -CookieJar $jarStaff)

    $adminLogin = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body '{"email":"admin@tcg.edu.ph","password":"Password123!"}' -CookieJar $jarAdmin
    Assert-Status 'admin login succeeds' 200 $adminLogin
    $adminCsrf = ((($adminLogin.Body | ConvertFrom-Json).data).csrf_token)
    if (-not $adminCsrf) { throw 'admin login did not return a CSRF token' }

    Assert-Status 'admin can list users' 200 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/users' -CookieJar $jarAdmin)

    # --- CSRF protection -------------------------------------------------------------
    $noCsrf = Invoke-Api -Method 'POST' -Path '/api/v1/admin/users' -CookieJar $jarAdmin `
        -Body '{"email":"x@tcg.edu.ph","password":"Password123!","role":"student","full_name":"X"}'
    Assert-Status 'write without CSRF token rejected (403)' 403 $noCsrf

    # --- Admin: create user -----------------------------------------------------------
    $stamp = Get-Date -Format 'yyyyMMddHHmmss'
    $newEmail = "lockme$stamp@tcg.edu.ph"
    $created = Invoke-Api -Method 'POST' -Path '/api/v1/admin/users' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $adminCsrf } `
        -Body ('{"email":"' + $newEmail + '","password":"LockTarget1","role":"student","full_name":"Lock Target","student_number":"' + $stamp + '"}')
    Assert-Status 'admin creates a student account (201)' 201 $created
    $createdId = ((($created.Body | ConvertFrom-Json).data).user.id)
    if (-not $createdId) { throw 'created user id missing' }

    $dup = Invoke-Api -Method 'POST' -Path '/api/v1/admin/users' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $adminCsrf } `
        -Body ('{"email":"' + $newEmail + '","password":"Password123!","role":"student","full_name":"Dup","student_number":"' + $stamp + 'x"}')
    Assert-Status 'duplicate email rejected with 409' 409 $dup

    $weak = Invoke-Api -Method 'POST' -Path '/api/v1/admin/users' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $adminCsrf } `
        -Body '{"email":"weak$stamp@tcg.edu.ph","password":"short","role":"staff","full_name":"Weak"}'
    Assert-Status 'weak password rejected with 422' 422 $weak

    # --- Account lockout (use case 4a) --------------------------------------------------
    for ($i = 1; $i -le 4; $i++) {
        $bad = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
            -Body ('{"email":"' + $newEmail + '","password":"WrongPass1"}')
        if ($bad.Status -ne 401) { throw "lockout attempt $i returned $($bad.Status), expected 401" }
    }
    $fifth = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body ('{"email":"' + $newEmail + '","password":"WrongPass1"}')
    Assert-Status '5th failure still 401 (lock engages)' 401 $fifth
    $sixth = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body ('{"email":"' + $newEmail + '","password":"LockTarget1"}')
    Assert-Status 'locked account rejected with 423 even with correct password' 423 $sixth

    # --- Disable + login refusal ----------------------------------------------------------
    $disabled = Invoke-Api -Method 'PATCH' -Path "/api/v1/admin/users/$createdId" -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $adminCsrf } -Body '{"status":"disabled"}'
    Assert-Status 'admin disables account' 200 $disabled

    $disabledLogin = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' `
        -Body ('{"email":"' + $newEmail + '","password":"LockTarget1"}')
    Assert-Status 'disabled account cannot log in (401)' 401 $disabledLogin

    $selfDisable = Invoke-Api -Method 'PATCH' -Path '/api/v1/admin/users/1' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $adminCsrf } -Body '{"status":"disabled"}'
    Assert-Status 'admin cannot disable own account (409)' 409 $selfDisable

    # --- Password reset round-trip -----------------------------------------------------------
    $student2 = 'student2@tcg.edu.ph'
    $newPassword = 'FreshPass2026'
    $requestReset = Invoke-Api -Method 'POST' -Path '/api/v1/auth/password/reset-request' `
        -Body ('{"email":"' + $student2 + '"}')
    Assert-Status 'reset request always 202 (no enumeration)' 202 $requestReset

    $log = Join-Path $ProjectRoot 'logs/app.log'
    $tokenLine = Select-String -LiteralPath $log -Pattern "reset token for $student2`: ([a-f0-9]{64})" |
        Select-Object -Last 1
    if (-not $tokenLine) { throw 'reset token not found in dev log' }
    $resetToken = $tokenLine.Matches[0].Groups[1].Value

    $doReset = Invoke-Api -Method 'POST' -Path '/api/v1/auth/password/reset' `
        -Body ('{"token":"' + $resetToken + '","password":"' + $newPassword + '"}')
    Assert-Status 'password reset succeeds with valid token' 200 $doReset

    $reusedToken = Invoke-Api -Method 'POST' -Path '/api/v1/auth/password/reset' `
        -Body ('{"token":"' + $resetToken + '","password":"AnotherPass1"}')
    Assert-Status 'reset token is single-use (400 on reuse)' 400 $reusedToken

    $student2Jar = Join-Path $Tmp 'student2.jar'
    Remove-Item $student2Jar -ErrorAction SilentlyContinue
    $student2Login = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $student2Jar `
        -Body ('{"email":"' + $student2 + '","password":"' + $newPassword + '"}')
    Assert-Status 'login works with new password' 200 $student2Login

    # --- Logout --------------------------------------------------------------------------------
    $logout = Invoke-Api -Method 'POST' -Path '/api/v1/auth/logout' -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $studentCsrf }
    Assert-Status 'logout returns 204' 204 $logout

    $meAfter = Invoke-Api -Method 'GET' -Path '/api/v1/me' -CookieJar $jarStudent
    Assert-Status 'session invalid after logout (401)' 401 $meAfter

    # --- Audit trail sanity ---------------------------------------------------------------------
    & 'C:\xampp\mysql\bin\mysql.exe' -u root -e "USE registrack; SELECT action, COUNT(*) AS n FROM audit_events GROUP BY action ORDER BY action;"
} finally {
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
}

Write-Output ''
Write-Output "== RESULT: $($script:pass) passed, $($script:fail) failed =="
if ($script:fail -gt 0) { exit 1 }
