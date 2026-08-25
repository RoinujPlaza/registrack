# REGIS-TRACK — reset the local dev database (schema + seed).
# WARNING: destroys all data in the registrack database. Dev use only.

$ErrorActionPreference = 'Stop'
$MySql = 'C:\xampp\mysql\bin\mysql.exe'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path

& $MySql -u root -e "DROP DATABASE IF EXISTS registrack;"
& $MySql -u root --default-character-set=utf8mb4 -e "source $($here -replace '\\','/')/schema.sql"
if ($LASTEXITCODE -ne 0) { throw 'schema apply failed' }
& $MySql -u root --default-character-set=utf8mb4 -e "source $($here -replace '\\','/')/seed.sql"
if ($LASTEXITCODE -ne 0) { throw 'seed apply failed' }

& $MySql -u root -e "USE registrack; SHOW TABLES;"
Write-Output 'dev database reset complete'
