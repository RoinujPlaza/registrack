# REGIS-TRACK - run every API suite sequentially.
#
# Each suite starts its own PHP server on a dedicated port. The suites assume a
# freshly seeded database, so pass -ResetDatabase on a development machine to
# drop and rebuild `registrack` before running (DESTRUCTIVE: dev data only).

param(
    [switch]$ResetDatabase,
    [string[]]$Suite = @('timezone', 'issuance', 'api', 'requests', 'workflow', 'notifications', 'reports', 'hardening'),
    [string]$MySql = 'C:\xampp\mysql\bin\mysql.exe'
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot

function Reset-Database {
    Write-Warning 'Dropping and rebuilding the development database registrack.'
    & $MySql -u root -e 'DROP DATABASE IF EXISTS registrack;'
    if ($LASTEXITCODE -ne 0) { throw 'database drop failed' }

    cmd /c "`"$MySql`" -u root < `"$projectRoot\database\schema.sql`""
    if ($LASTEXITCODE -ne 0) { throw 'schema load failed' }

    cmd /c "`"$MySql`" -u root < `"$projectRoot\database\seed.sql`""
    if ($LASTEXITCODE -ne 0) { throw 'seed load failed' }
}

if (-not $ResetDatabase) {
    Write-Warning 'Suites assume a pristine database. Use -ResetDatabase on a development machine.'
}

$failed = @()
foreach ($name in $Suite) {
    $script = Join-Path $PSScriptRoot "$name-tests.ps1"
    if (-not (Test-Path $script)) { throw "Unknown suite: $name" }

    if ($ResetDatabase) {
        Reset-Database
    }

    Write-Output ''
    Write-Output "=== $name ==="
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script
    if ($LASTEXITCODE -ne 0) {
        $failed += $name
    }
}

if ($failed.Count -gt 0) {
    Write-Output "Failed suites: $($failed -join ', ')"
    exit 1
}

Write-Output 'All suites passed.'
