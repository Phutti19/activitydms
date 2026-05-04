<?php
declare(strict_types=1);
// Caller ต้อง require_once auth.php + role check ก่อน include header.php
// Optional vars: $page_title (string), $page_active (string key), $extra_head (HTML)

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/flash.php';

$role         = $_SESSION['role'] ?? 'employee';
$display_name = $_SESSION['display_name'] ?? '';
$page_title   = $page_title  ?? APP_NAME;
$page_active  = $page_active ?? '';

$menus = [
    'admin' => [
        ['type'=>'item','key'=>'dashboard',           'label'=>'หน้าหลัก',           'icon'=>'bi-speedometer2',     'url'=>'/admin/dashboard.php'],
        ['type'=>'item','key'=>'activities',          'label'=>'กิจกรรม',            'icon'=>'bi-calendar-event',   'url'=>'/admin/manage_activities.php'],
        ['type'=>'item','key'=>'documents',           'label'=>'เอกสาร',             'icon'=>'bi-folder2-open',     'url'=>'/admin/manage_documents.php'],
        ['type'=>'item','key'=>'certificates',        'label'=>'เกียรติบัตร',         'icon'=>'bi-award',            'url'=>'/admin/manage_certificates.php'],
        ['type'=>'item','key'=>'calendar',            'label'=>'ปฏิทิน',             'icon'=>'bi-calendar3',        'url'=>'/admin/calendar.php'],
        ['type'=>'item','key'=>'reports',             'label'=>'รายงานสรุป',         'icon'=>'bi-graph-up',         'url'=>'/admin/reports.php'],
        ['type'=>'section','label'=>'จัดการ'],
        ['type'=>'item','key'=>'manage_users',        'label'=>'ผู้ใช้งาน',          'icon'=>'bi-people',           'url'=>'/admin/manage_users.php'],
        ['type'=>'item','key'=>'manage_fiscal_year',  'label'=>'ปีงบประมาณ',         'icon'=>'bi-calendar-range',   'url'=>'/admin/manage_fiscal_year.php'],
        ['type'=>'item','key'=>'manage_activity_types','label'=>'ประเภทกิจกรรม',    'icon'=>'bi-tag',              'url'=>'/admin/manage_activity_types.php'],
        ['type'=>'item','key'=>'notification_settings','label'=>'ตั้งค่าการแจ้งเตือน','icon'=>'bi-bell-fill',        'url'=>'/admin/notification_settings.php'],
    ],
    'director' => [
        ['type'=>'item','key'=>'dashboard',           'label'=>'หน้าหลัก',           'icon'=>'bi-speedometer2',     'url'=>'/director/dashboard.php'],
        ['type'=>'item','key'=>'activities',          'label'=>'กิจกรรม',            'icon'=>'bi-calendar-event',   'url'=>'/director/activities.php'],
        ['type'=>'item','key'=>'calendar',            'label'=>'ปฏิทิน',             'icon'=>'bi-calendar3',        'url'=>'/director/calendar.php'],
        ['type'=>'item','key'=>'reports',             'label'=>'รายงานสรุป',         'icon'=>'bi-graph-up',         'url'=>'/director/reports.php'],
    ],
    'employee' => [
        ['type'=>'item','key'=>'dashboard',           'label'=>'หน้าหลัก',           'icon'=>'bi-speedometer2',     'url'=>'/employee/dashboard.php'],
        ['type'=>'item','key'=>'my_activities',       'label'=>'กิจกรรมของฉัน',      'icon'=>'bi-person-check',     'url'=>'/employee/my_activities.php'],
        ['type'=>'item','key'=>'personal_activities', 'label'=>'กิจกรรมส่วนตัว',     'icon'=>'bi-journal-bookmark', 'url'=>'/employee/personal_activities.php'],
        ['type'=>'item','key'=>'available',           'label'=>'กิจกรรมที่เปิดรับ',  'icon'=>'bi-megaphone',        'url'=>'/employee/available_activities.php'],
        ['type'=>'item','key'=>'my_certificates',     'label'=>'เกียรติบัตรของฉัน',  'icon'=>'bi-award',            'url'=>'/employee/my_certificates.php'],
        ['type'=>'item','key'=>'documents',           'label'=>'เอกสารทั่วไป',       'icon'=>'bi-folder2-open',     'url'=>'/employee/documents.php'],
        ['type'=>'item','key'=>'my_calendar',         'label'=>'ปฏิทินของฉัน',       'icon'=>'bi-calendar3',        'url'=>'/employee/calendar.php'],
        ['type'=>'item','key'=>'my_reports',          'label'=>'รายงานของฉัน',       'icon'=>'bi-graph-up',         'url'=>'/employee/my_reports.php'],
    ],
];
$menu = $menus[$role] ?? [];

$initial = mb_substr(trim($display_name), 0, 1, 'UTF-8') ?: '?';
$header_role_label = ['admin'=>'ผู้ดูแลระบบ', 'director'=>'ผู้อำนวยการ', 'employee'=>'พนักงาน'][$role] ?? '';
$app_url = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $app_url ?>/assets/css/app.css" rel="stylesheet">
    <?= $extra_head ?? '' ?>
</head>
<body>

<nav class="app-topbar d-flex align-items-center px-3">
    <button class="btn btn-link text-dark p-2" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#sidebar"
            aria-controls="sidebar" aria-label="เปิดเมนู">
        <i class="bi bi-list fs-4"></i>
    </button>
    <span class="fw-bold ms-2"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></span>
    <div class="ms-auto">
        <span class="sidebar-user-avatar role-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
              style="width:32px;height:32px;font-size:13px;">
            <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
</nav>

<div class="app-layout d-flex flex-column flex-lg-row">

    <aside class="app-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">

        <div class="sidebar-mobile-header d-lg-none">
            <span id="sidebarLabel" class="fw-semibold">เมนู</span>
            <button type="button" class="btn-close btn-close-white"
                    data-bs-dismiss="offcanvas" data-bs-target="#sidebar"
                    aria-label="ปิด"></button>
        </div>

        <div class="sidebar-brand d-flex align-items-center gap-3">
            <span class="sidebar-brand-icon"><i class="bi bi-shield-check"></i></span>
            <div>
                <div class="fw-bold" style="font-size:13px;">ActivityDMS</div>
                <div style="font-size:12px;color:#94A3B8;">ARIT</div>
            </div>
        </div>

        <div class="sidebar-user d-flex align-items-center gap-3">
            <span class="sidebar-user-avatar role-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <div class="overflow-hidden flex-grow-1">
                <div class="text-truncate fw-semibold" style="font-size:12px;color:#fff;">
                    <?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <span class="badge-pill badge-role-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($header_role_label, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>

        <ul class="sidebar-nav">
            <?php foreach ($menu as $item): ?>
                <?php if ($item['type'] === 'section'): ?>
                    <li class="sidebar-section-label">
                        — <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?> —
                    </li>
                <?php else: ?>
                    <?php
                        $is_active   = ($page_active === $item['key']);
                        $is_disabled = empty($item['url']);
                        $href        = $is_disabled ? '#' : ($app_url . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8'));
                        $cls         = 'sidebar-nav-link'
                                       . ($is_active ? ' active' : '')
                                       . ($is_disabled ? ' disabled' : '');
                    ?>
                    <li>
                        <a href="<?= $href ?>" class="<?= $cls ?>"
                           <?= $is_disabled ? 'aria-disabled="true" tabindex="-1" title="เร็ว ๆ นี้"' : '' ?>>
                            <i class="bi <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i>
                            <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($is_disabled): ?>
                                <i class="bi bi-clock ms-auto" style="font-size:11px;opacity:0.6;"
                                   title="เร็ว ๆ นี้"></i>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-bottom">
            <a href="<?= $app_url ?>/change_password.php" class="sidebar-nav-link mb-1">
                <i class="bi bi-key"></i>
                <span>เปลี่ยนรหัสผ่าน</span>
            </a>
            <form method="POST" action="<?= $app_url ?>/logout.php" class="m-0">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-nav-link w-100 border-0 bg-transparent text-start">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>ออกจากระบบ</span>
                </button>
            </form>
        </div>
    </aside>

    <main class="app-main flex-grow-1">
        <?= flash_render() ?>
