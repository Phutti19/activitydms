<?php
declare(strict_types=1);
/**
 * api/calendar_events.php — FullCalendar JSON event feed
 *
 * GET params:
 *   start  (ISO date) — range start from FullCalendar
 *   end    (ISO date) — range end from FullCalendar
 *
 * Role-aware:
 *   admin / director → all organization activities
 *   employee         → registered org activities + own personal activities
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$role = current_role();
$uid  = (int) current_user_id();

$start = $_GET['start'] ?? '';
$end   = $_GET['end']   ?? '';

// Sanitize to date strings only
$start = preg_match('/^\d{4}-\d{2}-\d{2}/', $start) ? substr($start, 0, 10) : null;
$end   = preg_match('/^\d{4}-\d{2}-\d{2}/', $end)   ? substr($end,   0, 10) : null;

$pdo    = db();
$events = [];

// ---------------------------------------------------------------------------
// URL helper per role
// ---------------------------------------------------------------------------
$view_url = function(int $activity_id, string $scope) use ($role): string {
    if ($scope === 'personal') {
        return APP_URL . '/employee/personal_activities.php';
    }
    return match($role) {
        'admin'    => APP_URL . '/admin/activity_view.php?id=' . $activity_id,
        'director' => APP_URL . '/director/activity_view.php?id=' . $activity_id,
        default    => APP_URL . '/employee/activity_view.php?id=' . $activity_id,
    };
};

// ---------------------------------------------------------------------------
// Admin / Director — all org activities
// ---------------------------------------------------------------------------
if ($role === 'admin' || $role === 'director') {
    $where  = ['a.scope = "organization"'];
    $params = [];

    if ($start !== null) { $where[] = 'a.end_datetime >= :s';   $params[':s'] = $start . ' 00:00:00'; }
    if ($end   !== null) { $where[] = 'a.start_datetime <= :e'; $params[':e'] = $end   . ' 23:59:59'; }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
                t.name AS type_name, t.color AS type_color
         FROM activities a
         LEFT JOIN activity_types t ON t.id = a.activity_type_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.start_datetime'
    );
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $a) {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color'])
            ? $a['type_color'] : '#0EA5E9';
        $events[] = [
            'id'            => 'org_' . $a['id'],
            'title'         => $a['title'],
            'start'         => $a['start_datetime'],
            'end'           => $a['end_datetime'],
            'color'         => $color,
            'url'           => $view_url((int)$a['id'], 'organization'),
            'extendedProps' => [
                'type'     => $a['type_name'] ?? '',
                'location' => $a['location'] ?? '',
            ],
        ];
    }
}

// ---------------------------------------------------------------------------
// Employee — registered org activities + personal activities
// ---------------------------------------------------------------------------
if ($role === 'employee') {
    $where  = ['r.user_id = :uid', 'a.scope = "organization"'];
    $params = [':uid' => $uid];

    if ($start !== null) { $where[] = 'a.end_datetime >= :s';   $params[':s'] = $start . ' 00:00:00'; }
    if ($end   !== null) { $where[] = 'a.start_datetime <= :e'; $params[':e'] = $end   . ' 23:59:59'; }

    $stmt = $pdo->prepare(
        'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
                t.name AS type_name, t.color AS type_color,
                r.status AS reg_status
         FROM activity_registrations r
         JOIN activities a ON a.id = r.activity_id
         LEFT JOIN activity_types t ON t.id = a.activity_type_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.start_datetime'
    );
    $stmt->execute($params);

    $registered_ids = [];
    foreach ($stmt->fetchAll() as $a) {
        $registered_ids[(int)$a['id']] = true;
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color'])
            ? $a['type_color'] : '#0EA5E9';
        // ขอบทึบ = registered, จาง = absent
        if ($a['reg_status'] === 'absent') {
            $color = '#94A3B8';
        }
        $events[] = [
            'id'            => 'org_' . $a['id'],
            'title'         => $a['title'],
            'start'         => $a['start_datetime'],
            'end'           => $a['end_datetime'],
            'color'         => $color,
            'url'           => $view_url((int)$a['id'], 'organization'),
            'extendedProps' => [
                'type'     => $a['type_name'] ?? '',
                'location' => $a['location'] ?? '',
                'status'   => $a['reg_status'],
            ],
        ];
    }

    // Open-registration org activities (กิจกรรมที่ admin เปิดให้สมัคร แต่ employee ยังไม่ได้ลงทะเบียน)
    $where_open  = ['a.scope = "organization"', 'a.is_open_registration = 1'];
    $params_open = [];
    if ($start !== null) { $where_open[] = 'a.end_datetime >= :so';   $params_open[':so'] = $start . ' 00:00:00'; }
    if ($end   !== null) { $where_open[] = 'a.start_datetime <= :eo'; $params_open[':eo'] = $end   . ' 23:59:59'; }

    $stmt_open = $pdo->prepare(
        'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
                t.name AS type_name
         FROM activities a
         LEFT JOIN activity_types t ON t.id = a.activity_type_id
         WHERE ' . implode(' AND ', $where_open) . '
         ORDER BY a.start_datetime'
    );
    $stmt_open->execute($params_open);

    foreach ($stmt_open->fetchAll() as $a) {
        if (isset($registered_ids[(int)$a['id']])) continue; // ลงทะเบียนแล้ว ข้าม
        $events[] = [
            'id'            => 'open_' . $a['id'],
            'title'         => '📢 ' . $a['title'],
            'start'         => $a['start_datetime'],
            'end'           => $a['end_datetime'],
            'color'         => '#F59E0B',
            'borderColor'   => '#D97706',
            'url'           => APP_URL . '/employee/available_activities.php',
            'extendedProps' => [
                'type'     => $a['type_name'] ?? '',
                'location' => $a['location'] ?? '',
                'status'   => 'open',
            ],
        ];
    }

    // Personal activities
    $where2  = ['a.scope = "personal"', 'a.created_by = :uid2'];
    $params2 = [':uid2' => $uid];

    if ($start !== null) { $where2[] = 'a.end_datetime >= :s2';   $params2[':s2'] = $start . ' 00:00:00'; }
    if ($end   !== null) { $where2[] = 'a.start_datetime <= :e2'; $params2[':e2'] = $end   . ' 23:59:59'; }

    $stmt2 = $pdo->prepare(
        'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
                t.color AS type_color
         FROM activities a
         LEFT JOIN activity_types t ON t.id = a.activity_type_id
         WHERE ' . implode(' AND ', $where2) . '
         ORDER BY a.start_datetime'
    );
    $stmt2->execute($params2);

    foreach ($stmt2->fetchAll() as $a) {
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color'])
            ? $a['type_color'] : '#64748B';
        $events[] = [
            'id'            => 'personal_' . $a['id'],
            'title'         => '🔒 ' . $a['title'],
            'start'         => $a['start_datetime'],
            'end'           => $a['end_datetime'],
            'color'         => $color,
            'textColor'     => '#fff',
            'borderColor'   => $color,
            'url'           => $view_url((int)$a['id'], 'personal'),
            'extendedProps' => [
                'type'     => 'personal',
                'location' => $a['location'] ?? '',
            ],
        ];
    }
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode($events, JSON_UNESCAPED_UNICODE);
exit;
