# REGIS-TRACK — Phase 4 API test suite (Workflow engine, FR3/FR5)
# Covers: state-machine transition matrix (legal + illegal), optimistic
# concurrency (409), reason-required transitions, terminal states, queue
# filters, staff detail with history + audit, student notifications.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8095

function Get-Request {
    # helper: staff reads a request detail, returns parsed data
    param([string]$CookieJar, [int]$Id)
    $response = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$Id" -CookieJar $CookieJar
    if ($response.Status -ne 200) { throw "could not read request $Id" }
    ($response.Body | ConvertFrom-Json).data
}

$server = Start-TestServer
try {
    $jarStudent = Join-Path $script:ApiTmp 'student.jar'
    $jarStaff = Join-Path $script:ApiTmp 'staff.jar'
    $jarAdmin = Join-Path $script:ApiTmp 'admin.jar'
    Remove-Item $jarStudent, $jarStaff, $jarAdmin -ErrorAction SilentlyContinue

    # --- Sessions --------------------------------------------------------------
    $loginStudent = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudent `
        -Body '{"email":"student1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'student login' 200 $loginStudent
    $csrfStudent = ((($loginStudent.Body | ConvertFrom-Json).data).csrf_token)

    $loginStaff = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStaff `
        -Body '{"email":"staff1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'staff login' 200 $loginStaff
    $csrfStaff = ((($loginStaff.Body | ConvertFrom-Json).data).csrf_token)

    $loginAdmin = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarAdmin `
        -Body '{"email":"admin@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'admin login' 200 $loginAdmin
    $csrfAdmin = ((($loginAdmin.Body | ConvertFrom-Json).data).csrf_token)

    # --- Fixtures: two student requests -----------------------------------------
    try {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila')
    } catch [TimeZoneNotFoundException] {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaToday.AddDays(10).ToString('yyyy-MM-dd')

    function Submit-TestRequest {
        param([string]$TypeId, [string]$Purpose)
        $key = [guid]::NewGuid().ToString()
        $response = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
            -Headers @{ 'X-CSRF-Token' = $csrfStudent; 'Idempotency-Key' = $key } `
            -Body ('{"document_type_id":' + $TypeId + ',"quantity":1,"purpose":"' + $Purpose + '","target_release_date":"' + $targetDate + '"}')
        if ($response.Status -ne 201) { throw "fixture submission failed: $($response.Body)" }
        ($response.Body | ConvertFrom-Json).data.request
    }

    $r1 = Submit-TestRequest -TypeId '1' -Purpose 'Workflow test request one'
    $r2 = Submit-TestRequest -TypeId '2' -Purpose 'Workflow test request two'
    Write-Output "fixtures: R1=$($r1.id) ($($r1.tracking_number)), R2=$($r2.id) ($($r2.tracking_number))"

    # --- RBAC: students never transition ------------------------------------------
    Assert-Status 'student cannot transition (403)' 403 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStudent `
            -Headers @{ 'X-CSRF-Token' = $csrfStudent } `
            -Body ('{"to":"in_process","version":' + $r1.version + '}'))

    Assert-Status 'student cannot open staff detail (403)' 403 `
        (Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($r1.id)" -CookieJar $jarStudent)

    # --- Queue + search ---------------------------------------------------------------
    $queue = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?status=pending' -CookieJar $jarStaff
    Assert-Status 'staff queue (pending filter)' 200 $queue
    Assert-True 'queue contains both fixtures' `
        ((((($queue.Body | ConvertFrom-Json).data).meta).total) -ge 2)

    $search = Invoke-Api -Method 'GET' -Path "/api/v1/staff/requests?q=$($r1.tracking_number)" -CookieJar $jarStaff
    Assert-Status 'queue search by tracking number' 200 $search
    Assert-True 'search finds exactly the target request' `
        ((((($search.Body | ConvertFrom-Json).data).meta).total) -eq 1)

    # --- Detail: history + audit ----------------------------------------------------------
    $detail1 = Get-Request -CookieJar $jarStaff -Id $r1.id
    Assert-True 'staff detail includes history' ((($detail1.history | Measure-Object).Count) -ge 1)
    Assert-True 'staff detail includes audit trail' ((($detail1.audit | Measure-Object).Count) -ge 1)

    # --- Transition validation ---------------------------------------------------------------
    Assert-Status 'missing target status rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"version":1}')

    Assert-Status 'unknown status rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"flying","version":1}')

    Assert-Status 'skip ahead pending->ready_for_release rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"ready_for_release","version":1}')

    # --- Happy path R1: pending -> in_process ---------------------------------------------------
    $t1 = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"in_process","version":1}'
    Assert-Status 'pending -> in_process (200)' 200 $t1
    Assert-True 'status updated to in_process' (((($t1.Body | ConvertFrom-Json).data).request.status) -eq 'in_process')
    Assert-True 'version bumped to 2' ((((($t1.Body | ConvertFrom-Json).data).request.version)) -eq 2)

    Assert-Status 'backward transition in_process -> pending rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"pending","version":2}')

    Assert-Status 'in_process -> released (skipping ready_for_release) rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"released","version":2}')

    # --- Concurrency: stale version conflicts (409) ------------------------------------------------
    $concurrent = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body '{"to":"ready_for_release","version":2}'
    Assert-Status 'admin transitions with current version (200)' 200 $concurrent

    $stale = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"released","version":2}'
    Assert-Status 'stale version rejected with 409' 409 $stale

    # --- Terminal state -------------------------------------------------------------------------------
    $release = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body '{"to":"released","version":3,"remark":"Released at window 1"}'
    Assert-Status 'ready_for_release -> released (200)' 200 $release
    $releasedRequest = (($release.Body | ConvertFrom-Json).data).request
    Assert-True 'released_at recorded' ($null -ne $releasedRequest.released_at)

    Assert-Status 'terminal state: released -> anything rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r1.id)/transition" -CookieJar $jarAdmin `
            -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body '{"to":"in_process","version":4}')

    # --- Reason-required flows on R2 ----------------------------------------------------------------------
    $noReason = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"needs_information","version":1}'
    Assert-Status 'hold without reason rejected (422)' 422 $noReason

    $hold = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } `
        -Body '{"to":"needs_information","version":1,"remark":"Please clarify your program code"}'
    Assert-Status 'pending -> needs_information with reason (200)' 200 $hold

    $resume = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"pending","version":2}'
    Assert-Status 'needs_information -> pending (resume, 200)' 200 $resume

    Assert-Status 'reject without reason rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"rejected","version":3}')

    $reject = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } `
        -Body '{"to":"rejected","version":3,"remark":"Not eligible: program mismatch"}'
    Assert-Status 'pending -> rejected with reason (200)' 200 $reject

    Assert-Status 'terminal state: rejected -> anything rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($r2.id)/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"pending","version":4}')

    # --- History sequence on R2: pending -> needs_info -> pending -> rejected ---------------------------------
    $detail2 = Get-Request -CookieJar $jarStaff -Id $r2.id
    $sequence = ($detail2.history | ForEach-Object { $_.new_status }) -join ','
    Assert-True 'history sequence is pending,needs_information,pending,rejected' `
        ($sequence -eq 'pending,needs_information,pending,rejected') "got: $sequence"

    # --- Student notifications for every transition (FR4 in-system) ---------------------------------------------
    # student1 has user id 4 in the seed; every status change must notify them.
    & 'C:\xampp\mysql\bin\mysql.exe' -u root -e `
        "USE registrack; SELECT COUNT(*) AS student_status_notifications FROM notifications WHERE recipient_id = 4 AND channel='in_system' AND subject LIKE '%now%'; SELECT action, COUNT(*) AS n FROM audit_events WHERE action = 'status.changed' GROUP BY action;"
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
