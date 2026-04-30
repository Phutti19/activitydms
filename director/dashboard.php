<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('director');

$page_title = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">หน้าหลัก Director</h1>
</div>

<div class="card p-4">
    <p class="mb-1">
        สวัสดี <strong><?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
    </p>
    <p class="text-muted small mb-3">บทบาท: ผู้อำนวยการ (อ่านอย่างเดียว)</p>
    <div class="alert alert-info small mb-0">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Phase 2/3 stub</strong> — Director dashboard จริงจะมาใน Phase 10
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
