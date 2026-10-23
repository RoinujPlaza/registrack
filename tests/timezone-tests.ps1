# REGIS-TRACK - Time-zone regression suite
# Asserts that storage is UTC, API timestamps are Asia/Manila ISO-8601, and
# calendar-based outputs (tracking number, reports) use the Philippine date.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8097

$MySql = 'C:\xampp\mysql\bin\mysql.exe'
function Invoke-DbScalar {
    param([string]$Sql)
    $value = & $MySql -u root -N -B -e "USE registrack; $Sql;"
    return ([string]($value | Select-Object -Last 1)).Trim()
}

$server = Start-TestServer
try {
    $jarStudent = Join-Path $script:ApiTmp 'student.jar'
    $jarStaff = Join-Path $script:ApiTmp 'staff.jar'
    Remove-Item $jarStudent, $jarStaff -ErrorAction SilentlyContinue

    $login = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudent `
        -Body '{"email":"student1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'student login' 200 $login
    $csrf = ((($login.Body | ConvertFrom-Json).data).csrf_token)

    $loginStaff = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStaff `
        -Body '{"email":"staff1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'staff login' 200 $loginStaff

    try { $manilaNow = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila') } catch [TimeZoneNotFoundException] {
        $manilaNow = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaNow.AddDays(3).ToString('yyyy-MM-dd')

    # --- Submit one request and inspect every timestamp surface ------------------
    $submitted = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrf; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
        -Body ('{"document_type_id":1,"quantity":1,"purpose":"Timezone fixture","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'request submitted' 201 $submitted
    $created = (($submitted.Body | ConvertFrom-Json).data).request
    $requestId = $created.id

    Assert-True 'submitted_at carries +08:00 offset' ($created.submitted_at -match '\+08:00$') "got $($created.submitted_at)"
    $apiEpoch = ([DateTimeOffset]::Parse($created.submitted_at)).ToUnixTimeSeconds()
    $dbEpoch = [double](Invoke-DbScalar "SELECT UNIX_TIMESTAMP(submitted_at) FROM requests WHERE id=$requestId;")
    $drift = [Math]::Abs($apiEpoch - $dbEpoch)
    Assert-True 'API timestamp equals stored UTC + 8h' ($drift -le 2) "apiEpoch=$apiEpoch dbEpoch=$dbEpoch drift=$drift"

    $manilaYmd = $manilaNow.ToString('yyyyMMdd')
    Assert-True 'tracking number uses the Manila date' ($created.tracking_number -match "^RT-$manilaYmd-[2-9A-HJ-NP-Z]{6}$") "got $($created.tracking_number)"

    # --- Queue and detail projections -------------------------------------------
    $queue = Invoke-Api -Method 'GET' -Path '/api/v1/staff/requests?page_size=50' -CookieJar $jarStaff
    Assert-Status 'staff queue' 200 $queue
    $queueItem = ((($queue.Body | ConvertFrom-Json).data).items | Where-Object { $_.id -eq $requestId })
    Assert-True 'queue submitted_at carries +08:00 offset' ($queueItem.submitted_at -match '\+08:00$') "got $($queueItem.submitted_at)"

    $detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$requestId" -CookieJar $jarStaff
    Assert-Status 'staff detail' 200 $detail
    $history = ((($detail.Body | ConvertFrom-Json).data).history)
    Assert-True 'history created_at carries +08:00 offset' ($history[0].created_at -match '\+08:00$') "got $($history[0].created_at)"

    $notifications = Invoke-Api -Method 'GET' -Path '/api/v1/notifications' -CookieJar $jarStaff
    Assert-Status 'notification panel' 200 $notifications
    $firstNotification = ((($notifications.Body | ConvertFrom-Json).data).items)[0]
    Assert-True 'notification created_at carries +08:00 offset' ($firstNotification.created_at -match '\+08:00$') "got $($firstNotification.created_at)"

    # --- Report grouping uses the Philippine calendar day -----------------------
    $today = $manilaNow.ToString('yyyy-MM-dd')
    $report = Invoke-Api -Method 'GET' -Path "/api/v1/admin/reports/summary?from=$today&to=$today" -CookieJar $jarStaff
    Assert-Status 'report for today' 200 $report
    $summary = (($report.Body | ConvertFrom-Json).data).summary
    $todayRow = $summary.by_day | Where-Object { $_.day -eq $today }
    Assert-True 'report day bucket is the Manila date' ($null -ne $todayRow -and $todayRow.submitted -ge 1) "got $($summary.by_day | ConvertTo-Json -Compress)"
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
