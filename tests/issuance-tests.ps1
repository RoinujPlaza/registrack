# REGIS-TRACK - Issuance-policy regression suite
# Covers once-per-semester and once-only documents, the staff-only rejection
# rule for blocked duplicates, administrator overrides, cancelled requests not
# consuming an issuance, and the admin settings endpoints for terms and policies.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_api-lib.ps1')
Initialize-ApiTest -ProjectRoot (Split-Path -Parent $PSScriptRoot) -Port 8098

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
    $jarAdmin = Join-Path $script:ApiTmp 'admin.jar'
    Remove-Item $jarStudent, $jarStaff, $jarAdmin -ErrorAction SilentlyContinue

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

    try { $manilaNow = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Asia/Manila') } catch [TimeZoneNotFoundException] {
        $manilaNow = [System.TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow, 'Singapore Standard Time')
    }
    $targetDate = $manilaNow.AddDays(5).ToString('yyyy-MM-dd')

    function Submit-Request {
        param([int]$TypeId, [string]$Purpose)
        $response = Invoke-Api -Method 'POST' -Path '/api/v1/requests' -CookieJar $jarStudent `
            -Headers @{ 'X-CSRF-Token' = $csrfStudent; 'Idempotency-Key' = [guid]::NewGuid().ToString() } `
            -Body ('{"document_type_id":' + $TypeId + ',"quantity":1,"purpose":"' + $Purpose + '","target_release_date":"' + $targetDate + '"}')
        if ($response.Status -ne 201) { throw "submission failed: $($response.Body)" }
        $response
    }

    function Transition-Request {
        param([int]$Id, [int]$Version, [string]$To, [string]$Remark = '', [bool]$Override = $false)
        $body = @{ to = $To; version = $Version; remark = $Remark; override_issuance = $Override } | ConvertTo-Json -Compress
        Invoke-Api -Method 'POST' -Path "/api/v1/requests/$Id/transition" -CookieJar $jarStaff `
            -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body $body
    }

    function Transition-AsAdmin {
        param([int]$Id, [int]$Version, [string]$To, [string]$Remark = '', [bool]$Override = $false)
        $body = @{ to = $To; version = $Version; remark = $Remark; override_issuance = $Override } | ConvertTo-Json -Compress
        Invoke-Api -Method 'POST' -Path "/api/v1/requests/$Id/transition" -CookieJar $jarAdmin `
            -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body $body
    }

    function Release-Document {
        param([object]$Request)
        $step = Transition-Request -Id $Request.id -Version $Request.version -To 'in_process'
        if ($step.Status -ne 200) { throw "in_process failed: $($step.Body)" }
        $Request = (($step.Body | ConvertFrom-Json).data).request
        $step = Transition-Request -Id $Request.id -Version $Request.version -To 'ready_for_release'
        if ($step.Status -ne 200) { throw "ready_for_release failed: $($step.Body)" }
        $Request = (($step.Body | ConvertFrom-Json).data).request
        $step = Transition-Request -Id $Request.id -Version $Request.version -To 'released' -Remark 'Released for test'
        if ($step.Status -ne 200) { throw "released failed: $($step.Body)" }
        (($step.Body | ConvertFrom-Json).data).request
    }

    # --- once per semester: second request warns, approval is blocked ----------
    $coe1 = ((Submit-Request -TypeId 2 -Purpose 'COE first issuance').Body | ConvertFrom-Json).data.request
    $coe1 = Release-Document -Request $coe1

    $coe2Response = Submit-Request -TypeId 2 -Purpose 'COE duplicate request'
    $coe2 = (($coe2Response.Body | ConvertFrom-Json).data).request
    $coeWarnings = (((($coe2Response.Body | ConvertFrom-Json).data).warnings) -join ' ')
    Assert-True 'student sees a warnings message for a duplicate COE' ($coeWarnings -match 'already received') "got $coeWarnings"

    $coe2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($coe2.id)" -CookieJar $jarStaff
    Assert-Status 'staff detail of duplicate COE' 200 $coe2Detail
    $coe2Issuance = ((($coe2Detail.Body | ConvertFrom-Json).data).issuance)
    Assert-True 'COE policy is once per semester' ($coe2Issuance.policy -eq 'once_per_semester') "got $($coe2Issuance.policy)"
    Assert-True 'staff detail blocks the duplicate COE' ([bool]$coe2Issuance.blocked) "got $($coe2Issuance | ConvertTo-Json -Compress)"

    # --- Staff can reply to the student without a status change ----------------
    $emptyMessage = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($coe2.id)/message" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body '{"message":"   "}'
    Assert-Status 'empty staff message is rejected (422)' 422 $emptyMessage

    Assert-Status 'students cannot send staff messages (403)' 403 `
        (Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($coe2.id)/message" -CookieJar $jarStudent `
            -Headers @{ 'X-CSRF-Token' = $csrfStudent } -Body '{"message":"You already got this document."}')

    $studentMessage = 'You already received this Certificate of Enrollment. Please contact the Registrar if you need help.'
    $sendMessage = Invoke-Api -Method 'POST' -Path "/api/v1/requests/$($coe2.id)/message" -CookieJar $jarStaff `
        -Headers @{ 'X-CSRF-Token' = $csrfStaff } -Body (@{ message = $studentMessage } | ConvertTo-Json -Compress)
    Assert-Status 'staff can message the student (200)' 200 $sendMessage

    $studentDetail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/mine/$($coe2.tracking_number)" -CookieJar $jarStudent
    Assert-Status 'student reads the request after the staff message' 200 $studentDetail
    Assert-True 'staff message is the request latest remark' `
        (((($studentDetail.Body | ConvertFrom-Json).data).request.current_remark) -eq $studentMessage)

    $studentNotifications = Invoke-Api -Method 'GET' -Path '/api/v1/notifications?page_size=50' -CookieJar $jarStudent
    Assert-Status 'student notifications after the staff message' 200 $studentNotifications
    $messageNotification = ((($studentNotifications.Body | ConvertFrom-Json).data).items |
        Where-Object { $_.body -eq $studentMessage })
    Assert-True 'staff message appears instantly in the student notifications' ($null -ne $messageNotification) `
        "got $((($studentNotifications.Body | ConvertFrom-Json).data).items | ConvertTo-Json -Compress)"

    $messageAudit = [int](Invoke-DbScalar "SELECT COUNT(*) FROM audit_events WHERE action='request.message_sent' AND request_id=$($coe2.id);")
    Assert-True 'staff message is audited' ($messageAudit -eq 1) "got $messageAudit"

    $staffOverride = Transition-Request -Id $coe2.id -Version $coe2.version -To 'in_process' `
        -Remark 'Staff attempted to override the duplicate rule' -Override $true
    Assert-Status 'staff cannot override a blocked issuance (403)' 403 $staffOverride

    $coe2Process = Transition-Request -Id $coe2.id -Version $coe2.version -To 'in_process'
    Assert-Status 'duplicate COE can only be rejected (409)' 409 $coe2Process
    Assert-True 'blocked response explains the policy' ((($coe2Process.Body | ConvertFrom-Json).error.message) -match 'already received') "got $($coe2Process.Body)"

    $rejectWithoutReason = Transition-Request -Id $coe2.id -Version $coe2.version -To 'rejected'
    Assert-Status 'rejecting a duplicate without a reason is blocked (422)' 422 $rejectWithoutReason

    $rejectionReason = 'You already received this Certificate of Enrollment. This document is issued once per semester.'
    $coe2Reject = Transition-Request -Id $coe2.id -Version $coe2.version -To 'rejected' -Remark $rejectionReason
    Assert-Status 'staff can reject a blocked duplicate (200)' 200 $coe2Reject
    $coe2 = (($coe2Reject.Body | ConvertFrom-Json).data).request

    $coe2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($coe2.id)" -CookieJar $jarStaff
    $coe2History = ((($coe2Detail.Body | ConvertFrom-Json).data).history)
    Assert-True 'duplicate history ends with its own rejection' ($coe2History[-1].new_status -eq 'rejected') "got $($coe2History[-1].new_status)"
    Assert-True 'duplicate history does not contain the old release' (-not ($coe2History | Where-Object { $_.new_status -eq 'released' })) "got $($coe2History | ConvertTo-Json -Compress)"
    Assert-True 'rejection reason is visible in the duplicate history' ($coe2History[-1].remark -eq $rejectionReason) "got $($coe2History[-1].remark)"

    $coe1Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($coe1.id)" -CookieJar $jarStaff
    Assert-True 'released request no longer shows a duplicate alert' ($null -eq ((($coe1Detail.Body | ConvertFrom-Json).data).issuance)) "got $((($coe1Detail.Body | ConvertFrom-Json).data).issuance | ConvertTo-Json -Compress)"

    # --- once only: Transcript of Records --------------------------------------
    $tor1 = ((Submit-Request -TypeId 1 -Purpose 'TOR first issuance').Body | ConvertFrom-Json).data.request
    $tor1 = Release-Document -Request $tor1

    $tor2Response = Submit-Request -TypeId 1 -Purpose 'TOR duplicate request'
    $tor2 = (($tor2Response.Body | ConvertFrom-Json).data).request
    $tor2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($tor2.id)" -CookieJar $jarStaff
    $tor2Issuance = ((($tor2Detail.Body | ConvertFrom-Json).data).issuance)
    Assert-True 'TOR policy is once only' ($tor2Issuance.policy -eq 'once_ever') "got $($tor2Issuance.policy)"
    Assert-True 'TOR duplicate is blocked' ([bool]$tor2Issuance.blocked) "got $($tor2Issuance | ConvertTo-Json -Compress)"

    $tor2Process = Transition-Request -Id $tor2.id -Version $tor2.version -To 'in_process'
    Assert-Status 'TOR duplicate can only be rejected (409)' 409 $tor2Process

    $tor2Reject = Transition-Request -Id $tor2.id -Version $tor2.version -To 'rejected' `
        -Remark 'You already received your Transcript of Records. This document is issued once only.'
    Assert-Status 'TOR duplicate can be rejected (200)' 200 $tor2Reject

    # --- once only: graduation certification -----------------------------------
    $grad1 = ((Submit-Request -TypeId 4 -Purpose 'Graduation certificate first').Body | ConvertFrom-Json).data.request
    $grad1 = Release-Document -Request $grad1

    $grad2Response = Submit-Request -TypeId 4 -Purpose 'Graduation certificate duplicate'
    $grad2 = (($grad2Response.Body | ConvertFrom-Json).data).request
    $grad2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($grad2.id)" -CookieJar $jarStaff
    $grad2Issuance = ((($grad2Detail.Body | ConvertFrom-Json).data).issuance)
    Assert-True 'graduation certificate policy is once only' ($grad2Issuance.policy -eq 'once_ever') "got $($grad2Issuance.policy)"
    Assert-True 'graduation duplicate is blocked' ([bool]$grad2Issuance.blocked) "got $($grad2Issuance | ConvertTo-Json -Compress)"
    Assert-True 'graduation message says only once' ($grad2Issuance.message -match 'only once') "got $($grad2Issuance.message)"

    $gradStaffOverride = Transition-Request -Id $grad2.id -Version $grad2.version -To 'in_process' `
        -Remark 'Staff attempted to override the graduation duplicate' -Override $true
    Assert-Status 'staff cannot override a graduation duplicate (403)' 403 $gradStaffOverride

    $grad2Process = Transition-Request -Id $grad2.id -Version $grad2.version -To 'in_process'
    Assert-Status 'graduation duplicate can only be rejected (409)' 409 $grad2Process

    $gradOverride = Transition-AsAdmin -Id $grad2.id -Version $grad2.version -To 'in_process' `
        -Remark 'Administrator approved an exception after verifying the student record' -Override $true
    Assert-Status 'administrator override with a written reason succeeds (200)' 200 $gradOverride
    $grad2 = (($gradOverride.Body | ConvertFrom-Json).data).request

    $overrideAudit = [int](Invoke-DbScalar "SELECT COUNT(*) FROM audit_events WHERE action='request.issuance_override' AND request_id=$($grad2.id);")
    Assert-True 'administrator override is recorded in the audit trail' ($overrideAudit -eq 1) "got $overrideAudit"

    $grad2Reject = Transition-Request -Id $grad2.id -Version $grad2.version -To 'rejected' `
        -Remark 'You already received your Certificate of Graduation. This document is issued once only.'
    Assert-Status 'graduation duplicate can still be rejected (200)' 200 $grad2Reject
    $grad2 = (($grad2Reject.Body | ConvertFrom-Json).data).request

    $grad2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($grad2.id)" -CookieJar $jarStaff
    Assert-True 'rejected graduation request no longer shows a duplicate alert' ($null -eq ((($grad2Detail.Body | ConvertFrom-Json).data).issuance)) "got $((($grad2Detail.Body | ConvertFrom-Json).data).issuance | ConvertTo-Json -Compress)"

    # --- cancelled requests do not consume an issuance -------------------------
    $grade1 = ((Submit-Request -TypeId 3 -Purpose 'Grade certification cancelled').Body | ConvertFrom-Json).data.request
    $cancel = Invoke-Api -Method 'POST' -Path "/api/v1/requests/mine/$($grade1.tracking_number)/cancel" -CookieJar $jarStudent `
        -Headers @{ 'X-CSRF-Token' = $csrfStudent } -Body '{"reason":"No longer needed"}'
    Assert-Status 'grade certification cancelled' 200 $cancel

    $grade2 = ((Submit-Request -TypeId 3 -Purpose 'Grade certification replacement').Body | ConvertFrom-Json).data.request
    $grade2Detail = Invoke-Api -Method 'GET' -Path "/api/v1/requests/$($grade2.id)" -CookieJar $jarStaff
    $grade2Issuance = ((($grade2Detail.Body | ConvertFrom-Json).data).issuance)
    Assert-True 'cancelled request does not block a new grade certification' (-not [bool]$grade2Issuance.blocked) "got $($grade2Issuance | ConvertTo-Json -Compress)"

    # --- Admin settings: terms and policies ------------------------------------
    $settingsResponse = Invoke-Api -Method 'GET' -Path '/api/v1/admin/settings' -CookieJar $jarAdmin
    Assert-Status 'admin reads settings' 200 $settingsResponse
    $settings = (($settingsResponse.Body | ConvertFrom-Json).data)
    Assert-True 'settings lists expanded document catalogue' ((@($settings.document_types) | Measure-Object).Count -ge 20)
    Assert-True 'settings lists academic terms' ((@($settings.terms) | Measure-Object).Count -ge 6)
    Assert-True 'settings exposes the policy catalogue' ((@($settings.policies) | Measure-Object).Count -eq 4)
    Assert-True 'settings exposes catalogue groups' ((@($settings.catalog_groups) | Measure-Object).Count -eq 5)
    Assert-True 'settings exposes item types' ((@($settings.item_types) | Measure-Object).Count -eq 2)
    Assert-True 'settings includes CAV and registrar forms' (
        ((@($settings.document_types | Where-Object { $_.catalog_group -eq 'cav' }) | Measure-Object).Count -ge 4) -and
        ((@($settings.document_types | Where-Object { $_.catalog_group -eq 'other' }) | Measure-Object).Count -ge 8)
    )

    Assert-Status 'staff cannot read admin settings (403)' 403 `
        (Invoke-Api -Method 'GET' -Path '/api/v1/admin/settings' -CookieJar $jarStaff)

    $policyChange = Invoke-Api -Method 'PATCH' -Path '/api/v1/admin/document-types/2' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } `
        -Body '{"issuance_policy":"once_per_school_year","policy_note":"Temporarily once per school year."}'
    Assert-Status 'admin changes an issuance policy' 200 $policyChange
    Assert-True 'policy change is returned' (((($policyChange.Body | ConvertFrom-Json).data).document_type.issuance_policy) -eq 'once_per_school_year')

    $restorePolicy = Invoke-Api -Method 'PATCH' -Path '/api/v1/admin/document-types/2' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } `
        -Body '{"issuance_policy":"once_per_semester","policy_note":"Issued once per semester."}'
    Assert-Status 'admin restores the issuance policy' 200 $restorePolicy

    # --- Admin catalogue CRUD + deactivation --------------------------------
    $newTypeName = 'Test Catalogue Item ' + [guid]::NewGuid().ToString('N').Substring(0, 8)
    $createTypeBody = @{
        name = $newTypeName
        catalog_group = 'cav'
        item_type = 'service'
        issuance_policy = 'unlimited'
        is_active = $true
    } | ConvertTo-Json -Compress
    $createType = Invoke-Api -Method 'POST' -Path '/api/v1/admin/document-types' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body $createTypeBody
    Assert-Status 'admin creates a catalogue item' 201 $createType
    $createdType = ((($createType.Body | ConvertFrom-Json).data).document_type)
    Assert-True 'new catalogue item keeps its group and type' `
        ($createdType.catalog_group -eq 'cav' -and $createdType.item_type -eq 'service')
    Assert-True 'new catalogue item goes to the end of the picker by default' `
        ($createdType.sort_order -eq 1000)

    $duplicateType = Invoke-Api -Method 'POST' -Path '/api/v1/admin/document-types' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body $createTypeBody
    Assert-Status 'duplicate catalogue item is rejected (409)' 409 $duplicateType

    $updateTypeBody = @{
        name = $newTypeName + ' Updated'
        catalog_group = 'other'
        item_type = 'service'
        is_active = $false
    } | ConvertTo-Json -Compress
    $updateType = Invoke-Api -Method 'PATCH' -Path "/api/v1/admin/document-types/$($createdType.id)" `
        -CookieJar $jarAdmin -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body $updateTypeBody
    Assert-Status 'admin updates and deactivates a catalogue item' 200 $updateType
    $updatedType = ((($updateType.Body | ConvertFrom-Json).data).document_type)
    Assert-True 'catalogue edit persists group and active state' `
        ($updatedType.catalog_group -eq 'other' -and -not [bool]$updatedType.is_active)

    $studentTypes = Invoke-Api -Method 'GET' -Path '/api/v1/document-types' -CookieJar $jarStudent
    Assert-Status 'student reads the active catalogue' 200 $studentTypes
    Assert-True 'deactivated catalogue item is hidden from students' `
        ((@((($studentTypes.Body | ConvertFrom-Json).data).items | Where-Object { $_.id -eq $createdType.id }) | Measure-Object).Count -eq 0)

    $currentTermId = $settings.current_term_id
    $newTerm = Invoke-Api -Method 'POST' -Path '/api/v1/admin/terms' -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } `
        -Body '{"school_year":"2027-2028","semester":"first","starts_on":"2027-08-02","ends_on":"2027-12-17","is_current":false}'
    Assert-Status 'admin creates an academic term' 201 $newTerm
    $newTermId = ((($newTerm.Body | ConvertFrom-Json).data).term.id)

    $setCurrent = Invoke-Api -Method 'PATCH' -Path "/api/v1/admin/terms/$newTermId" -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body '{"is_current":true}'
    Assert-Status 'admin sets the current term' 200 $setCurrent

    $restoreCurrent = Invoke-Api -Method 'PATCH' -Path "/api/v1/admin/terms/$currentTermId" -CookieJar $jarAdmin `
        -Headers @{ 'X-CSRF-Token' = $csrfAdmin } -Body '{"is_current":true}'
    Assert-Status 'admin restores the original current term' 200 $restoreCurrent
} finally {
    Stop-TestServer $server
}

Get-ApiTestSummary
