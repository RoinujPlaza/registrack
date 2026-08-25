# REGIS-TRACK — shared API test harness (used by all tests/*.ps1 suites)
# PowerShell 5.1 compatible. Call Initialize-ApiTest before anything else.

function Initialize-ApiTest {
    param([string]$ProjectRoot, [string]$Port)
    $script:ApiProjectRoot = $ProjectRoot
    $script:ApiBaseUrl = "http://127.0.0.1:$Port"
    $script:ApiTmp = Join-Path $env:TEMP "registrack-tests-$PID"
    New-Item -ItemType Directory -Path $script:ApiTmp -Force | Out-Null
    $script:ApiBodyFile = Join-Path $script:ApiTmp 'last-body.json'
    # NOTE: distinct name from $ApiBodyFile — PowerShell variables are
    # case-insensitive, so near-collisions silently overwrite each other.
    $script:ApiReqBodyFile = Join-Path $script:ApiTmp 'req-body.json'
    $script:ApiPass = 0
    $script:ApiFail = 0
}

function Start-TestServer {
    $process = Start-Process -FilePath 'php' `
        -ArgumentList '-S', ($script:ApiBaseUrl -replace 'http://', ''), '-t', 'public' `
        -WorkingDirectory $script:ApiProjectRoot -PassThru -WindowStyle Hidden
    $ready = $false
    foreach ($i in 1..20) {
        Start-Sleep -Milliseconds 500
        try {
            $health = Invoke-Api -Method 'GET' -Path '/health'
            if ($health.Status -eq 200) { $ready = $true; break }
        } catch { }
    }
    if (-not $ready) {
        Stop-TestServer $process
        throw "Dev server did not become healthy at $($script:ApiBaseUrl)"
    }
    Write-Output "== server ready on $($script:ApiBaseUrl) =="
    return $process
}

function Stop-TestServer { param($Process)
    Stop-Process -Id $Process.Id -Force -ErrorAction SilentlyContinue
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [string]$Body,
        [string]$CookieJar,
        [hashtable]$Headers
    )
    $curlArgs = @('-s', '-S', '-o', $script:ApiBodyFile, '-w', '%{http_code}', '-X', $Method, "$($script:ApiBaseUrl)$Path")
    if ($CookieJar) { $curlArgs += @('-b', $CookieJar, '-c', $CookieJar) }
    if ($Body) {
        # PS 5.1 quirks: Set-Content -Encoding UTF8 adds a BOM that breaks
        # json_decode — write BOM-less bytes instead.
        [System.IO.File]::WriteAllText($script:ApiReqBodyFile, $Body, (New-Object System.Text.UTF8Encoding($false)))
        $curlArgs += @('-H', 'Content-Type: application/json', '--data-binary', "@$($script:ApiReqBodyFile)")
    }
    if ($Headers) {
        foreach ($key in $Headers.Keys) {
            $curlArgs += @('-H', "$($key): $($Headers[$key])")
        }
    }
    $status = & curl.exe @curlArgs
    $responseBody = ''
    if (Test-Path $script:ApiBodyFile) { $responseBody = Get-Content $script:ApiBodyFile -Raw -ErrorAction SilentlyContinue }
    [pscustomobject]@{ Status = [int]($status -as [int]); Body = $responseBody }
}

function Assert-Status {
    param([string]$Name, [int]$Expected, [object]$Result)
    if ($Result.Status -eq $Expected) {
        $script:ApiPass++
        Write-Output "PASS  [$($Result.Status)] $Name"
    } else {
        $script:ApiFail++
        Write-Output "FAIL  [got $($Result.Status), want $Expected] $Name"
        Write-Output "      body: $($Result.Body)"
    }
}

function Assert-True {
    param([string]$Name, [bool]$Condition, [string]$Detail)
    if ($Condition) {
        $script:ApiPass++
        Write-Output "PASS  $Name"
    } else {
        $script:ApiFail++
        Write-Output "FAIL  $Name"
        if ($Detail) { Write-Output "      $Detail" }
    }
}

function Get-ApiTestSummary {
    Write-Output ''
    Write-Output "== RESULT: $($script:ApiPass) passed, $($script:ApiFail) failed =="
    if ($script:ApiFail -gt 0) { exit 1 }
}
