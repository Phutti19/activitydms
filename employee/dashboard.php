<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>หน้าหลัก — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }</style>
</head>
<body class="p-4">
    <div class="container">
        <h1 class="h4 fw-semibold">หน้าหลัก Employee</h1>
        <p class="text-muted">
            สวัสดี <?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($_SESSION['role'], ENT_QUOTES, 'UTF-8') ?>)
        </p>
        <div class="alert alert-info small">
            <strong>Phase 2 stub</strong> — Dashboard จริงจะมาใน Phase 7
        </div>
        <form method="POST" action="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/logout.php">
            <?php require_once __DIR__ . '/../includes/csrf.php'; echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline-secondary btn-sm">ออกจากระบบ</button>
        </form>
    </div>
</body>
</html>
