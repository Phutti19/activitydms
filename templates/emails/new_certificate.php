<?php
// Available vars: $name, $activity_title, $cert_id, $app_url
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ออกเกียรติบัตรใหม่</title>
</head>
<body style="font-family: 'Sarabun', 'Tahoma', Arial, sans-serif; background: #F8FAFC; margin: 0; padding: 24px; color: #0F172A;">
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto;">
    <tr>
        <td style="background: linear-gradient(135deg, #F59E0B, #D97706); padding: 24px; text-align: center; border-radius: 14px 14px 0 0;">
            <h1 style="color: #fff; margin: 0; font-size: 18px; font-weight: 600;">
                🏆 ออกเกียรติบัตรใหม่
            </h1>
        </td>
    </tr>
    <tr>
        <td style="background: #fff; padding: 24px; border: 1px solid #E2E8F0; border-top: 0; border-radius: 0 0 14px 14px;">
            <p style="margin: 0 0 16px; font-size: 15px;">เรียน <strong><?= $esc($name) ?></strong></p>
            <p style="margin: 0 0 16px; color: #475569;">
                คุณได้รับเกียรติบัตรจากการเข้าร่วมกิจกรรม:
            </p>

            <table cellpadding="0" cellspacing="0" border="0" width="100%"
                   style="background: #FFFBEB; border: 2px solid #FDE68A; border-radius: 8px; margin: 16px 0;">
                <tr><td style="padding: 24px; text-align: center;">
                    <div style="font-size: 36px; margin-bottom: 8px;">🏆</div>
                    <h2 style="margin: 0; font-size: 16px; color: #78350F;">
                        <?= $esc($activity_title) ?>
                    </h2>
                </td></tr>
            </table>

            <p style="text-align: center; margin: 24px 0;">
                <a href="<?= $esc($app_url) ?>/index.php"
                   style="background: linear-gradient(135deg, #F59E0B, #D97706); color: #fff; padding: 12px 28px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: 500;">
                    เข้าสู่ระบบเพื่อดาวน์โหลดเกียรติบัตร
                </a>
            </p>

            <hr style="border: 0; border-top: 1px solid #E2E8F0; margin: 24px 0;">
            <p style="font-size: 12px; color: #94A3B8; margin: 0;">
                อีเมลฉบับนี้ส่งจากระบบ <?= $esc(APP_NAME) ?> โดยอัตโนมัติ — กรุณาอย่าตอบกลับ
            </p>
        </td>
    </tr>
</table>
</body>
</html>
