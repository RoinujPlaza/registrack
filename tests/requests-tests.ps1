# REGIS-TRACK — Phase 3 API test suite (Request submission, FR2)
# Covers: validation matrix, idempotent submission, duplicate-window warning,
# ownership/IDOR boundaries, cancellation, atomic audit/history/notification.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8094

$server = Start-TestServer
try {
    $jarStudentA = Join-Path $script:ApiTmp 'studentA.jar'
    $jarStudentB = Join-Path $script:ApiTmp 'studentB.jar'
    $jarStaff = Join-Path $script:ApiTmp 'staff.jar'
    Remove-Item $jarStudentA, $jarStudentB, $jarStaff -ErrorAction SilentlyContinue

    # --- Sessions --------------------------------------------------------------
    $loginA = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudentA `
        -Body '{"email":"student1@tcg.edu.ph","password":"Password123!"}'
    Assert-Status 'student A login' 200 $loginA
    $csrfA = ((($loginA.Body | ConvertFrom-Json).data).csrf_token)

    $loginB = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudentB `
        -Body '{"email":"student3@tcg.edu.ph","password":"Password123!"}'
    Assert-Status 'student B login' 200 $loginB
    $csrfB = ((($loginB.Body | ConvertFrom-Json).data).csrf_token)

    $loginStaff = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStaff `
        -Body '{"email":"staff1@tcg.edu.ph","password":"Password123!"}'
    Assert-Status 'staff login' 200 $loginStaff
    $csrfStaff = ((($loginStaff.Body | ConvertFrom-Json).data).csrf_token)

    # --- Document types ----------------------------------------------------------
    $types = Invoke-Api -Method 'GET' -Path '/api/v1/document-types' -CookieJar $jarStudentA
    Assert-Status 'document types list for authenticated user' 200 $types
    Assert-True 'document types seeded (>= 5 active)' `
        (((($types.Body | ConvertFrom-Json).data).items | Measure-Object).Count -ge 5)

    # --- RBAC: only students submit ------------------------------------------------
    Assert-Status 'staff cannot submit a request (403)' 403 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } `
            -Body '{"document_type_id":1,"quantity":1,"purpose":"staff attempt","target_release_date":"2026-12-01","idempotency":"aaaaaaaa-1111-2222-3333-444444444444"}')

    # --- Submission ------------------------------------------------------------------
    # Windows lacks IANA tz IDs; 'Singapore Standard Time' is the same UTC+8 as Manila.
    try {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila')
    } catch [TimeZoneNotFoundException] {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaToday.AddDays(7).ToString('yyyy-MM-dd')
    $idemA1 = [guid]::NewGuid().ToString()

    $submitted = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
        -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = $idemA1 } `
        -Body ('{"document_type_id":1,"quantity":1,"purpose":"Official transcript for scholarship application","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'valid submission created (201)' 201 $submitted
    $tracking1 = ((($submitted.Body | ConvertFrom-Json).data).request.tracking_number)
    Assert-True 'tracking number matches RT-YYYYMMDD-XXXXXX' `
        ($tracking1 -match '^RT-\d{8}-[A-Z0-9]{6}$') "got: $tracking1"
    $version1 = ((($submitted.Body | ConvertFrom-Json).data).request.version)
    Assert-True 'initial version is 1' ($version1 -eq 1)

    # --- Idempotent replay ---------------------------------------------------------------
    $replay = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
        -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = $idemA1 } `
        -Body ('{"document_type_id":1,"quantity":1,"purpose":"Official transcript for scholarship application","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'same idempotency key replays as 200' 200 $replay
    Assert-True 'replay returns the SAME tracking number' `
        (((($replay.Body | ConvertFrom-Json).data).request.tracking_number) -eq $tracking1)

    # --- Duplicate window warning -----------------------------------------------------------
    $idemA2 = [guid]::NewGuid().ToString()
    $duplicate = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
        -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = $idemA2 } `
        -Body ('{"document_type_id":1,"quantity":2,"purpose":"Second copy for visa application","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'legitimate second request still created (201)' 201 $duplicate
    Assert-True 'duplicate-window warning present' `
        ((((($duplicate.Body | ConvertFrom-Json).data).warnings) | Measure-Object).Count -ge 1)
    $tracking2 = ((($duplicate.Body | ConvertFrom-Json).data).request.tracking_number)

    # --- Validation matrix --------------------------------------------------------------------
    Assert-Status 'quantity 0 rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":1,"quantity":0,"purpose":"zero qty test","target_release_date":"' + $targetDate + '"}'))

    Assert-Status 'quantity 11 rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":1,"quantity":11,"purpose":"over qty test","target_release_date":"' + $targetDate + '"}'))

    $yesterday = $manilaToday.AddDays(-1).ToString('yyyy-MM-dd')
    Assert-Status 'past target date rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":1,"quantity":1,"purpose":"past date test","target_release_date":"' + $yesterday + '"}'))

    Assert-Status 'unknown document type rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":999,"quantity":1,"purpose":"bad type test","target_release_date":"' + $targetDate + '"}'))

    Assert-Status 'short purpose rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":1,"quantity":1,"purpose":"ab","target_release_date":"' + $targetDate + '"}'))

    Assert-Status 'missing idempotency key rejected (422)' 422 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA } `
            -Body ('{"document_type_id":1,"quantity":1,"purpose":"no key test","target_release_date":"' + $targetDate + '"}'))

    Assert-Status 'write without CSRF rejected (403)' 403 `
        (Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudentA `
            -Headers @{ 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
            -Body ('{"document_type_id":1,"quantity":1,"purpose":"no csrf test","target_release_date":"' + $targetDate + '"}'))

    # --- Ownership / IDOR boundaries -------------------------------------------------------------
    Assert-Status 'student B cannot read student A request by tracking number (404)' 404 `
        (Invoke-Api -Method 'GET' -Path "/api/v1/requests/mine/$tracking1" -CookieJar $jarStudentB)

    Assert-Status 'unknown tracking number returns 404' 404 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/requests/mine/RT-19700101-ZZZZZZ' -CookieJar $jarStudentA)

    $listA = Invoke-Api -Method 'GET' -Path '/api/v1/requests/mine' -CookieJar $jarStudentA
    Assert-Status 'student A lists own requests' 200 $listA
    Assert-True 'student A sees exactly 2 requests' `
        ((((($listA.Body | ConvertFrom-Json).data).meta).total) -eq 2)

    $listB = Invoke-Api -Method 'GET' -Path '/api/v1/requests/mine' -CookieJar $jarStudentB
    Assert-Status 'student B lists own requests' 200 $listB
    Assert-True 'student B sees 0 requests (no leakage)' `
        ((((($listB.Body | ConvertFrom-Json).data).meta).total) -eq 0)

    # --- Detail + history --------------------------------------------------------------------------
    $detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/mine/$tracking1" -CookieJar $jarStudentA
    Assert-Status 'student A reads own request detail' 200 $detail
    $historyCount = ((((($detail.Body | ConvertFrom-Json).data).history) | Measure-Object).Count)
    Assert-True 'history contains the submission entry' ($historyCount -ge 1)

    # --- Cancellation --------------------------------------------------------------------------------
    $cancelled = Invoke-Api -Method 'POST' -Path "/api/v1/requests/mine/$tracking1/cancel" -CookieJar $jarStudentA `
        -Headers @{ 'X-CSRF-Token' = $csrfA } -Body '{"reason":"No longer needed"}'
    Assert-Status 'student cancels own pending request' 200 $cancelled
    Assert-True 'status is cancelled after cancel' `
        (((($cancelled.Body | ConvertFrom-Json).data).request.status) -eq 'cancelled')

    $detailAfter = Invoke-Api -Method 'GET' -Path "/api/v1/requests/mine/$tracking1" -CookieJar $jarStudentA
    $historyAfter = (((($detailAfter.Body | ConvertFrom-Json).data).history) | Measure-Object).Count
    Assert-True 'history grew after cancellation (pending + cancelled entries)' ($historyAfter -ge 2)

    Assert-Status 'cancelling again conflicts (409, no longer pending)' 409 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/mine/$tracking1/cancel" -CookieJar $jarStudentA `
            -Headers @{ 'X-CSRF-Token' = $csrfA } -Body '{}')

    Assert-Status 'student B cannot cancel student A request (404)' 404 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/mine/$tracking2/cancel" -CookieJar $jarStudentB `
            -Headers @{ 'X-CSRF-Token' = $csrfB } -Body '{}')

    # --- Atomicity sanity: audit + notifications written with the request ------------------------------
    & 'C:\xampp\mysql\bin\mysql.exe' -u root -e "USE registrack; SELECT action, COUNT(*) AS n FROM audit_events WHERE action LIKE 'request.%' GROUP BY action; SELECT COUNT(*) AS staff_notifications FROM notifications WHERE channel='in_system'; SELECT COUNT(*) AS history_rows FROM request_history;"
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
