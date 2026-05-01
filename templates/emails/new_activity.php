<?php
// Available vars: $name, $activity_title, $start, $end, $location, $type_name, $app_url
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$fmt = function($dt) use ($months) {
    $ts = strtotime((string)$dt);
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' '
         . (date('Y', $ts) + 543) . ' เวลา ' . date('H:i', $ts) . ' น.';
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>กิจกรรมใหม่</title>
</head>
<body style="font-family: 'Sarabun', 'Tahoma', Arial, sans-serif; background: #F8FAFC; margin: 0; padding: 24px; color: #0F172A;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto;">
    <tr>
        <td style="background: linear-gradient(135deg, #38BDF8, #0EA5E9); padding: 24px; text-align: center; border-radius: 14px 14px 0 0;">
            <h1 style="color: #fff; margin: 0; font-size: 18px; font-weight: 600;">
                📅 มีกิจกรรมใหม่ที่คุณได้รับการเพิ่มเข้าร่วม
            </h1>
        </td>
    </tr>
    <tr>
        <td style="background: #fff; padding: 24px; border: 1px solid #E2E8F0; border-top: 0; border-radius: 0 0 14px 14px;">
            <p style="margin: 0 0 16px; font-size: 15px;">เรียน <strong><?= $esc($name) ?></strong></p>
            <p style="margin: 0 0 16px; color: #475569;">
                ผู้ดูแลระบบได้เพิ่มคุณเข้าร่วมกิจกรรมขององค์กรดังนี้:
            </p>

            <table cellpadding="0" cellspacing="0" border="0" width="100%"
                   style="background: #F8FAFC; border-left: 4px solid #0EA5E9; border-radius: 6px; margin: 16px 0;">
                <tr><td style="padding: 16px;">
                    <h2 style="margin: 0 0 12px; font-size: 16px; color: #0F172A;">
                        <?= $esc($activity_title) ?>
                    </h2>
                    <?php if (!empty($type_name)): ?>
                    <div style="margin: 6px 0; color: #475569; font-size: 14px;">
                        <strong>ประเภท:</strong> <?= $esc($type_name) ?>
                    </div>
                    <?php endif; ?>
                    <div style="margin: 6px 0; color: #475569; font-size: 14px;">
                        <strong>วันเวลาเริ่ม:</strong> <?= $esc($fmt($start)) ?>
                    </div>
                    <div style="margin: 6px 0; color: #475569; font-size: 14px;">
                        <strong>วันเวลาสิ้นสุด:</strong> <?= $esc($fmt($end)) ?>
                    </div>
                    <?php if (!empty($location)): ?>
                    <div style="margin: 6px 0; color: #475569; font-size: 14px;">
                        <strong>สถานที่:</strong> <?= $esc($location) ?>
                    </div>
                    <?php endif; ?>
                </td></tr>
            </table>

            <p style="text-align: center; margin: 24px 0;">
                <a href="<?= $esc($app_url) ?>/index.php"
                   style="background: linear-gradient(135deg, #0EA5E9, #0284C7); color: #fff; padding: 12px 28px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: 500;">
                    เข้าสู่ระบบเพื่อดูรายละเอียด
                </a>
            </p>

            <hr style="border: 0; border-top: 1px solid #E2E8F0; margin: 24px 0;">
            <p style="font-size: 12px; color: #94A3B8; margin: 0;">
                อีเมลฉบับนี้ส่งจากระบบ <?= $esc(APP_NAME) ?> (สำนักวิทยบริการและเทคโนโลยีสารสนเทศ — ARIT) โดยอัตโนมัติ — กรุณาอย่าตอบกลับ
            </p>
        </td>
    </tr>
</table>
</body>
</html>
