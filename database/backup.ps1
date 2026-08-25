# REGIS-TRACK — backup the registrack database.
# Writes a timestamped SQL dump to backups/ and prunes dumps older than
# -RetentionDays (default 30). Schedule daily in production; run before
# every deployment in any environment.

param([int]$RetentionDays = 30)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$backupDir = Join-Path $projectRoot 'backups'
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$target = Join-Path $backupDir "registrack-$stamp.sql"

# --databases makes the dump self-contained (includes CREATE DATABASE + USE),
# so restore.ps1 works even against an empty server.
& 'C:\xampp\mysql\bin\mysqldump.exe' -u root --single-transaction --routines --triggers --databases registrack | Out-File -FilePath $target -Encoding utf8
if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed' }

$size = (Get-Item $target).Length
Write-Output "backup written: $target ($size bytes)"

# Prune old backups
$cutoff = (Get-Date).AddDays(-$RetentionDays)
Get-ChildItem $backupDir -Filter 'registrack-*.sql' |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    ForEach-Object { Remove-Item $_.FullName; Write-Output "pruned: $($_.Name)" }

Write-Output "backup complete (retention: $RetentionDays days)"
