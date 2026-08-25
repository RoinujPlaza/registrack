# REGIS-TRACK — restore the registrack database from a backup dump.
# Destroys the current database, imports the dump, and verifies the result.
# Usage: powershell -File database\restore.ps1 [-BackupFile path\to\dump.sql]

param([string]$BackupFile)

$ErrorActionPreference = 'Stop'
$MySql = 'C:\xampp\mysql\bin\mysql.exe'
$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$backupDir = Join-Path $projectRoot 'backups'

if ($BackupFile -eq '') {
    $latest = Get-ChildItem $backupDir -Filter 'registrack-*.sql' -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($null -eq $latest) { throw "No backup found in $backupDir. Pass -BackupFile explicitly." }
    $BackupFile = $latest.FullName
}
if (-not (Test-Path $BackupFile)) { throw "Backup file not found: $BackupFile" }

Write-Output "restoring from: $BackupFile"

& $MySql -u root -e "DROP DATABASE IF EXISTS registrack;"
if ($LASTEXITCODE -ne 0) { throw 'drop failed' }

# Defensive: dumps made without --databases assume the schema exists.
& $MySql -u root -e "CREATE DATABASE IF NOT EXISTS registrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw 'create database failed' }

$sourcePath = (Resolve-Path $BackupFile).Path -replace '\\', '/'
& $MySql -u root --default-character-set=utf8mb4 -e "source $sourcePath"
if ($LASTEXITCODE -ne 0) { throw 'import failed' }

# Verification: expected schema shape after a valid backup.
$tables = [int] (& $MySql -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='registrack';")
$users = [int] (& $MySql -u root -N -e "USE registrack; SELECT COUNT(*) FROM users;")

Write-Output "verify: tables=$tables (expect 8), users=$users"
if ($tables -lt 8) { throw "Restore verification FAILED: only $tables tables present." }

Write-Output 'restore complete and verified'
