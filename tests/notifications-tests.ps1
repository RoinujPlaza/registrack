# REGIS-TRACK — Phase 5 API test suite (Notifications, FR4)
# Covers: dual-channel queueing (in-system always, email per institution
# toggle), notification panel + ownership, mark-read idempotency, cron
# dispatcher happy path (file transport), retry -> exhausted failure path,
# cron heartbeat.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8096

$MySql = 'C:\xampp\mysql\bin\mysql.exe'
function Invoke-Db { param([string]$Sql) & $MySql -u root -e "USE registrack; $Sql;" }

function Dispatch {
    # runs the cron dispatcher; returns stdout.
    # NOTE: Config maps notifications.smtp_host -> env NOTIFICATIONS_SMTP_HOST.
    param([string]$SmtpHost = '', [string]$SmtpPort = '', [string]$RetryDelay = '')
    $oldHost = $env:NOTIFICATIONS_SMTP_HOST; $oldPort = $env:NOTIFICATIONS_SMTP_PORT; $oldDelay = $env:NOTIFICATIONS_RETRY_DELAY_MINUTES
    if ($SmtpHost -ne '') { $env:NOTIFICATIONS_SMTP_HOST = $SmtpHost }
    if ($SmtpPort -ne '') { $env:NOTIFICATIONS_SMTP_PORT = $SmtpPort }
    if ($RetryDelay -ne '') { $env:NOTIFICATIONS_RETRY_DELAY_MINUTES = $RetryDelay }
    try {
        (& php cron\dispatch_notifications.php 2>&1 | Select-Object -Last 1)
    } finally {
        foreach ($pair in @(@('NOTIFICATIONS_SMTP_HOST', $oldHost), @('NOTIFICATIONS_SMTP_PORT', $oldPort), @('NOTIFICATIONS_RETRY_DELAY_MINUTES', $oldDelay))) {
            if ($null -ne $pair[1]) { Set-Item -Path "Env:$($pair[0])" -Value $pair[1] } else { Remove-Item "Env:$($pair[0])" -ErrorAction SilentlyContinue }
        }
    }
}

$server = Start-TestServer
try {
    $jarStudent = Join-Path $script:ApiTmp 'student.jar'
    $jarStaff = Join-Path $script:ApiTmp 'staff.jar'
    Remove-Item $jarStudent, $jarStaff -ErrorAction SilentlyContinue

    $loginStudent = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudent `
        -Body '{"email":"student1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'student login' 200 $loginStudent
    $csrfStudent = ((($loginStudent.Body | ConvertFrom-Json).data).csrf_token)

    $loginStaff = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStaff `
        -Body '{"email":"staff1@tcgc.edu.ph","password":"Password123!"}'
    Assert-Status 'staff login' 200 $loginStaff
    $csrfStaff = ((($loginStaff.Body | ConvertFrom-Json).data).csrf_token)

    # --- Enable the institution email channel -----------------------------------
    Invoke-Db "UPDATE system_settings SET setting_value='1' WHERE setting_key='notification_email_enabled';"

    # --- Submission queues BOTH channels for staff --------------------------------
    try { $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila') } catch [TimeZoneNotFoundException] {
        $manilaToday = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaToday.AddDays(5).ToString('yyyy-MM-dd')

    $submitted = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrfStudent; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
        -Body ('{"document_type_id":1,"quantity":1,"purpose":"Notification test request","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'submission created (201)' 201 $submitted
    $requestId = ((($submitted.Body | ConvertFrom-Json).data).request.id)

    $emailRows = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE request_id=$requestId AND channel='email';" | Select-Object -Last 1)
    Assert-True 'email rows queued for staff when channel enabled' ($emailRows -eq 2) "got $emailRows"

    # --- Status change queues email for the student ----------------------------------
    $detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$requestId" -CookieJar $jarStaff
    $transition = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$requestId/transition" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"to":"in_process","version":1}'
    Assert-Status 'staff transition succeeds' 200 $transition

    $studentEmailRows = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE request_id=$requestId AND channel='email' AND recipient_id=4;" | Select-Object -Last 1)
    Assert-True 'student email row queued on status change' ($studentEmailRows -eq 1) "got $studentEmailRows"

    # --- Notification panel ------------------------------------------------------------
    $panel = Invoke-Api -Method 'GET' -Path '/api/v1/notifications' -CookieJar $jarStudent
    Assert-Status 'student notification list' 200 $panel
    $panelData = ($panel.Body | ConvertFrom-Json).data
    Assert-True 'panel meta carries unread count' ($panelData.meta.unread -ge 1)
    $unreadBefore = $panelData.meta.unread
    $firstNotificationId = $panelData.items[0].id

    $me = Invoke-Api -Method 'GET' -Path '/api/v1/me' -CookieJar $jarStudent
    Assert-True '/me exposes unread_count' `
        (((($me.Body | ConvertFrom-Json).data).unread_count) -eq $unreadBefore)

    # --- Ownership: another student cannot touch these notifications --------------------
    $jarStudentB = Join-Path $script:ApiTmp 'studentB.jar'
    Remove-Item $jarStudentB -ErrorAction SilentlyContinue
    $loginB = Invoke-Api -Method 'POST' -Path '/api/v1/auth/login' -CookieJar $jarStudentB `
        -Body '{"email":"student3@tcgc.edu.ph","password":"Password123!"}'
    $csrfB = ((($loginB.Body | ConvertFrom-Json).data).csrf_token)
    Assert-Status 'foreign notification id returns 404' 404 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/notifications/$firstNotificationId/read" -CookieJar $jarStudentB `
            -Headers @{ 'X-CSRF-Token' = $csrfB })

    # --- Mark read: idempotent, unread decreases ------------------------------------------
    $mark1 = Invoke-Api -Method 'POST' -Path "/api/v1/notifications/$firstNotificationId/read" -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrfStudent }
    Assert-Status 'mark read succeeds' 200 $mark1
    $unreadAfter1 = ((($mark1.Body | ConvertFrom-Json).data).unread)
    Assert-True 'unread count decreased' ($unreadAfter1 -eq ($unreadBefore - 1))

    $mark2 = Invoke-Api -Method 'POST' -Path "/api/v1/notifications/$firstNotificationId/read" -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrfStudent }
    Assert-Status 'mark read is idempotent (still 200)' 200 $mark2
    Assert-True 'unread unchanged on second mark' `
        ((((($mark2.Body | ConvertFrom-Json).data).unread)) -eq $unreadAfter1)

    # --- Dispatcher happy path (file transport) ---------------------------------------------
    $outbox = Join-Path $script:ApiProjectRoot 'storage/mail-outbox'
    $outboxBefore = (Get-ChildItem $outbox -Recurse -Filter '*.eml' -ErrorAction SilentlyContinue | Measure-Object).Count

    $dispatch1 = Dispatch
    Assert-True 'dispatcher reports sends' ($dispatch1 -match '(\d+) due, (\d+) sent')
    $dueCount1 = [int]$Matches[1]; $sentCount1 = [int]$Matches[2]
    Assert-True 'all due notifications sent' ($dueCount1 -eq $sentCount1) "due=$dueCount1 sent=$sentCount1"

    $outboxAfter = (Get-ChildItem $outbox -Recurse -Filter '*.eml' -ErrorAction SilentlyContinue | Measure-Object).Count
    Assert-True 'file transport wrote .eml files' (($outboxAfter - $outboxBefore) -ge $sentCount1)

    $pendingEmails = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE channel='email' AND delivery_status IN ('created','failed');" | Select-Object -Last 1)
    Assert-True 'no email rows left pending after dispatch' ($pendingEmails -eq 0)

    $dispatch2 = Dispatch
    Assert-True 'second dispatch run has nothing due (no re-sends)' ($dispatch2 -match ' 0 due, 0 sent')

    # --- Retry -> exhausted failure path (unreachable SMTP via env override) --------------------
    Invoke-Db "UPDATE notifications SET delivery_status='created', attempts=0, last_attempt_at=NULL WHERE channel='email' AND delivery_status IN ('sent','failed','exhausted');"

    $fail1 = Dispatch -SmtpHost '127.0.0.1' -SmtpPort '1' -RetryDelay '0'
    Assert-True 'first failure marks rows failed' ((($fail1 -match '(\d+) failed') -and ([int]$Matches[1] -ge 1))) "output: $fail1"
    $failedState = [string] (Invoke-Db "SELECT delivery_status FROM notifications WHERE channel='email' LIMIT 1;" | Select-Object -Last 1)
    Assert-True 'status is failed (retryable)' ($failedState -eq 'failed') "got $failedState"

    Dispatch -SmtpHost '127.0.0.1' -SmtpPort '1' -RetryDelay '0' | Out-Null
    $fail3 = Dispatch -SmtpHost '127.0.0.1' -SmtpPort '1' -RetryDelay '0'
    Assert-True 'third failure exhausts the rows' ((($fail3 -match '(\d+) failed') -and ([int]$Matches[1] -ge 1))) "output: $fail3"

    $exhausted = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE channel='email' AND delivery_status='exhausted';" | Select-Object -Last 1)
    Assert-True 'exhausted rows flagged for manual follow-up' ($exhausted -ge 1) "got $exhausted"

    $exhaustedRetry = Dispatch -SmtpHost '127.0.0.1' -SmtpPort '1' -RetryDelay '0'
    Assert-True 'exhausted rows are never retried' (($exhaustedRetry -match '^\[registrack\] dispatcher: 0 due'))

    # --- Cron heartbeat ---------------------------------------------------------------------------
    $heartbeat = [string] (Invoke-Db "SELECT setting_value FROM system_settings WHERE setting_key='cron_last_run_at';" | Select-Object -Last 1)
    Assert-True 'cron heartbeat recorded' ($heartbeat -match '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$') "got: $heartbeat"

    # --- Channel disabled: no email rows queued ------------------------------------------------------
    Invoke-Db "UPDATE system_settings SET setting_value='0' WHERE setting_key='notification_email_enabled';"
    $submitted2 = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrfStudent; 'Idempotency-Key' = ([guid]::NewGuid().ToString()) } `
        -Body ('{"document_type_id":2,"quantity":1,"purpose":"Email disabled test","target_release_date":"' + $targetDate + '"}')
    Assert-Status 'second submission created' 201 $submitted2
    $requestId2 = ((($submitted2.Body | ConvertFrom-Json).data).request.id)
    $emailRows2 = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE request_id=$requestId2 AND channel='email';" | Select-Object -Last 1)
    $inSystemRows2 = [int] (Invoke-Db "SELECT COUNT(*) FROM notifications WHERE request_id=$requestId2 AND channel='in_system';" | Select-Object -Last 1)
    Assert-True 'email disabled -> no email rows' ($emailRows2 -eq 0) "got $emailRows2"
    Assert-True 'in-system rows always created' ($inSystemRows2 -ge 2)
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
