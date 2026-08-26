# REGIS-TRACK — Phase 6 API test suite (Search + Reports, FR6)
# Covers: staff queue filter matrix + pagination, report aggregates (JSON),
# zero-value empty state, CSV download with audit logging, access control,
# filter validation.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8097

$server = Start-TestServer
try {
    $jarStudent = Join-Path $script:ApiTmp 'student.jar'
    $jarStaff = Join-Path $script:ApiTmp 'staff.jar'
    Remove-Item $jarStudent, $jarStaff -ErrorAction SilentlyContinue

    # --- Sessions --------------------------------------------------------------
    $loginStudent = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudent `
        -Body '{"email":"student1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'student login' 200 $loginStudent
    $csrfStudent = ((($loginStudent.Body | ConvertFrom-Json).data).csrf_token)

    $loginStaff = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStaff `
        -Body '{"email":"staff1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'staff login' 200 $loginStaff
    $csrfStaff = ((($loginStaff.Body | ConvertFrom-Json).data).csrf_token)

    # --- Fixtures: 3 requests -> 1 released (TOR), 1 rejected (TOR), 1 pending (COE) ---
    try { $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila') } catch [TimeZoneNotFoundException] {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaToday.AddDays(5).ToString('yyyy-MM-dd')

    function Submit-Fixture {
        param([int]$TypeId, [string]$Purpose)
        $response = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
            -Headers @{ 'X-CSRF-Token' = $csrfStudent; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
            -Body ('{"document_type_id":' + $TypeId + ',"quantity":1,"purpose":"' + $Purpose + '","target_release_date":"' + $targetDate + '"}')
        if ($response.Status -ne 201) { throw "fixture failed: $($response.Body)" }
        ($response.Body | ConvertFrom-Json).data.request
    }

    function Transition-Fixture {
        param([int]$Id, [int]$Version, [string]$To, [string]$Remark)
        $response = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$Id/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } `
            -Body ('{"to":"' + $To + '","version":' + $Version + ',"remark":"' + $Remark + '"}')
        if ($response.Status -ne 200) { throw "transition to $To failed: $($response.Body)" }
        (($response.Body | ConvertFrom-Json).data).request
    }

    $r1 = Submit-Fixture -TypeId 1 -Purpose 'Report fixture released'
    [void](Transition-Fixture -Id $r1.id -Version 1 -To 'in_process' -Remark '')
    [void](Transition-Fixture -Id $r1.id -Version 2 -To 'ready_for_release' -Remark '')
    $r1 = Transition-Fixture -Id $r1.id -Version 3 -To 'released' -Remark 'Window 1'

    $r2 = Submit-Fixture -TypeId 1 -Purpose 'Report fixture rejected'
    $r2 = Transition-Fixture -Id $r2.id -Version 1 -To 'rejected' -Remark 'Not eligible'

    $r3 = Submit-Fixture -TypeId 2 -Purpose 'Report fixture pending'

    # --- Queue filter matrix -----------------------------------------------------
    $q1 = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?status=pending' -CookieJar $jarStaff
    Assert-Status 'queue filter status=pending' 200 $q1
    Assert-True 'status=pending finds exactly the pending fixture' `
        ((((($q1.Body | ConvertFrom-Json).data).meta).total) -eq 1)

    $q2 = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?document_type_id=1' -CookieJar $jarStaff
    Assert-True 'type filter finds the two TOR fixtures' `
        ((((($q2.Body | ConvertFrom-Json).data).meta).total) -eq 2)

    $q3 = Invoke-Api -Method 'GET' -Path "/api/v1/staff/requests?status=released&document_type_id=1" -CookieJar $jarStaff
    Assert-True 'combined status+type filter finds one' `
        ((((($q3.Body | ConvertFrom-Json).data).meta).total) -eq 1)

    $q4 = Invoke-Api -Method 'GET' -Path "/api/v1/staff/requests?q=$($r3.tracking_number)" -CookieJar $jarStaff
    Assert-True 'q search by tracking finds one' `
        ((((($q4.Body | ConvertFrom-Json).data).meta).total) -eq 1)

    $todayUtc = (Get-Date).ToUniversalTime().ToString('yyyy-MM-dd')
    $tomorrowUtc = (Get-Date).ToUniversalTime().AddDays(1).ToString('yyyy-MM-dd')
    $q5 = Invoke-Api -Method 'GET' -Path "/api/v1/staff/requests?date_from=$todayUtc&date_to=$todayUtc" -CookieJar $jarStaff
    Assert-True 'today date range finds all three' `
        ((((($q5.Body | ConvertFrom-Json).data).meta).total) -eq 3)

    $q6 = Invoke-Api -Method 'GET' -Path "/api/v1/staff/requests?date_from=$tomorrowUtc" -CookieJar $jarStaff
    Assert-True 'future date range finds none' `
        ((((($q6.Body | ConvertFrom-Json).data).meta).total) -eq 0)

    # --- Pagination ------------------------------------------------------------------
    $page1 = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?page=1&page_size=2' -CookieJar $jarStaff
    $page2 = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?page=2&page_size=2' -CookieJar $jarStaff
    Assert-True 'page 1 returns 2 items of 3' `
        (((((($page1.Body | ConvertFrom-Json).data).items) | Measure-Object).Count) -eq 2 -and
         ((((($page1.Body | ConvertFrom-Json).data).meta).total) -eq 3))
    Assert-True 'page 2 returns the remaining item' `
        (((((($page2.Body | ConvertFrom-Json).data).items) | Measure-Object).Count) -eq 1)

    # --- Access control -----------------------------------------------------------------
    Assert-Status 'student blocked from reports (403)' 403 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary' -CookieJar $jarStudent)

    # --- Report aggregates (JSON) ------------------------------------------------------------
    $report = Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary' -CookieJar $jarStaff
    Assert-Status 'summary report (default 30-day window)' 200 $report
    $summary = (($report.Body | ConvertFrom-Json).data).summary
    Assert-True 'totals.submitted is 3' ($summary.totals.submitted -eq 3)
    Assert-True 'by_status has released=1' ($summary.totals.by_status.released -eq 1)
    Assert-True 'by_status has rejected=1' ($summary.totals.by_status.rejected -eq 1)
    Assert-True 'by_status has pending=1' ($summary.totals.by_status.pending -eq 1)
    Assert-True 'by_document_type has 2 rows' ((($summary.by_document_type | Measure-Object).Count) -eq 2)
    $torRow = $summary.by_document_type | Where-Object { $_.document_type_name -like 'Transcript*' }
    Assert-True 'TOR row: total 2, released 1, rejected 1' `
        ($torRow.total -eq 2 -and $torRow.released -eq 1 -and $torRow.rejected -eq 1)
    $todayRow = $summary.by_day | Where-Object { $_.day -eq $todayUtc }
    Assert-True 'by_day today: submitted 3, released 1' `
        ($todayRow.submitted -eq 3 -and $todayRow.released -eq 1)

    # --- Filtered report ------------------------------------------------------------------------
    $reportReleased = Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary?status=released' -CookieJar $jarStaff
    $releasedSummary = (($reportReleased.Body | ConvertFrom-Json).data).summary
    Assert-True 'filtered report totals.submitted is 1' ($releasedSummary.totals.submitted -eq 1)

    # --- Zero-value empty state (Figure 14) --------------------------------------------------------
    $farFuture = (Get-Date).ToUniversalTime().AddDays(60).ToString('yyyy-MM-dd')
    $reportEmpty = Invoke-Api -Method 'GET' -Path "/api/v1/admin/reports/summary?from=$farFuture&to=$farFuture" -CookieJar $jarStaff
    Assert-Status 'empty-range report still 200' 200 $reportEmpty
    $emptySummary = (($reportEmpty.Body | ConvertFrom-Json).data).summary
    Assert-True 'empty report totals zero' ($emptySummary.totals.submitted -eq 0)
    Assert-True 'empty report carries informational message' ($null -ne $emptySummary.message -and $emptySummary.message.Length -gt 10)

    # --- Filter validation -----------------------------------------------------------------------------
    Assert-Status 'bad date format rejected (422)' 422 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary?from=not-a-date' -CookieJar $jarStaff)
    Assert-Status 'from > to rejected (422)' 422 `
        (Invoke-Api -Method 'GET' -Path "/api/v1/admin/reports/summary?from=$tomorrowUtc&to=$todayUtc" -CookieJar $jarStaff)
    Assert-Status 'range over 366 days rejected (422)' 422 `
        (Invoke-Api -Method 'GET' -Path "/api/v1/admin/reports/summary?from=2020-01-01&to=$todayUtc" -CookieJar $jarStaff)
    Assert-Status 'unknown status filter rejected (422)' 422 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary?status=flying' -CookieJar $jarStaff)

    # --- CSV export + audit -------------------------------------------------------------------------------
    $csvResponse = Invoke-Api -Method 'GET' -Path '/api/v1/admin/reports/summary?format=csv' -CookieJar $jarStaff
    Assert-Status 'CSV export 200' 200 $csvResponse
    Assert-True 'CSV contains header row' ($csvResponse.Body -match 'document_type,total')
    Assert-True 'CSV contains TOR summary row' `
        ($csvResponse.Body -match 'Transcript of Records \(TOR\)",2,0,0,0,0,1,1,0') "body: $($csvResponse.Body)"

    $exportAudits = & 'C:\xampp\mysql\bin\mysql.exe' -u root -N -e "USE registrack; SELECT COUNT(*) FROM audit_events WHERE action='report.exported';"
    Assert-True 'CSV export audited (report.exported)' ([int]$exportAudits -ge 1) "got $exportAudits"
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
