# ActivityDMS — clean_uploads.ps1
# ล้างไฟล์อัปโหลดทั้งหมดในโฟลเดอร์ UPLOAD_PATH
# คงเหลือโครงสร้างโฟลเดอร์ย่อย (photos / attachments / documents / certificates)
#
# วิธีใช้:
#   pwsh scripts/clean_uploads.ps1
#   pwsh scripts/clean_uploads.ps1 -DryRun       # ดูว่าจะลบอะไรบ้างโดยยังไม่ลบจริง
#   pwsh scripts/clean_uploads.ps1 -Path "D:/activitydms-uploads"

param(
    [string]$Path,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# อ่าน UPLOAD_PATH จาก .env ถ้าไม่ได้ส่ง -Path มา
if (-not $Path) {
    $envFile = Join-Path $PSScriptRoot '..\.env'
    if (-not (Test-Path $envFile)) {
        Write-Error ".env not found at $envFile — ส่ง -Path มาเองได้"
        exit 1
    }
    $line = Select-String -Path $envFile -Pattern '^\s*UPLOAD_PATH\s*=\s*(.+)$' | Select-Object -First 1
    if (-not $line) {
        Write-Error "ไม่พบ UPLOAD_PATH ใน .env"
        exit 1
    }
    $Path = $line.Matches[0].Groups[1].Value.Trim().Trim('"').Trim("'")
}

if (-not (Test-Path $Path)) {
    Write-Warning "ไม่พบโฟลเดอร์ $Path — ไม่มีอะไรต้องลบ"
    exit 0
}

$subdirs = @('photos', 'attachments', 'documents', 'certificates')

Write-Host "Target: $Path" -ForegroundColor Cyan
if ($DryRun) { Write-Host "[DRY RUN] ไม่ลบจริง" -ForegroundColor Yellow }

$totalCount = 0
$totalBytes = 0

foreach ($sub in $subdirs) {
    $dir = Join-Path $Path $sub
    if (-not (Test-Path $dir)) {
        Write-Host "  - $sub/ (not exist, skip)" -ForegroundColor DarkGray
        continue
    }

    $files = Get-ChildItem -Path $dir -File -Recurse -Force
    $count = $files.Count
    $bytes = ($files | Measure-Object -Property Length -Sum).Sum
    if (-not $bytes) { $bytes = 0 }

    $totalCount += $count
    $totalBytes += $bytes

    $sizeMB = [math]::Round($bytes / 1MB, 2)
    Write-Host "  - $sub/ : $count files ($sizeMB MB)" -ForegroundColor White

    if (-not $DryRun -and $count -gt 0) {
        $files | Remove-Item -Force -Confirm:$false
        # ลบ subfolder เปล่าด้วย แต่คง root ของ $sub ไว้
        Get-ChildItem -Path $dir -Directory -Recurse -Force |
            Sort-Object FullName -Descending |
            ForEach-Object {
                if (-not (Get-ChildItem -Path $_.FullName -Force)) {
                    Remove-Item -Path $_.FullName -Force -Confirm:$false
                }
            }
    }
}

$totalMB = [math]::Round($totalBytes / 1MB, 2)
Write-Host ""
if ($DryRun) {
    Write-Host "[DRY RUN] จะลบ $totalCount files ($totalMB MB)" -ForegroundColor Yellow
} else {
    Write-Host "ลบเรียบร้อย: $totalCount files ($totalMB MB)" -ForegroundColor Green
}
