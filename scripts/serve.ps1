# ActivityDMS — รัน built-in PHP server บน localhost:8000
# ใช้ php-dev.ini เพื่อยกเพดาน upload (default 2M ต่ำเกินไป)

$ProjectRoot = (Resolve-Path "$PSScriptRoot\..").Path
$IniFile     = Join-Path $ProjectRoot 'php-dev.ini'

Write-Host "Starting PHP dev server at http://localhost:8000" -ForegroundColor Green
Write-Host "Document root: $ProjectRoot"
Write-Host "Using ini file: $IniFile"
Write-Host ""

Push-Location $ProjectRoot
try {
    & php -c $IniFile -S localhost:8000
} finally {
    Pop-Location
}
