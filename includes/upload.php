<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// MIME whitelist (สเปค §7.3 + CLAUDE.md ข้อ 9)
// key = MIME จริงจาก finfo, value = extension ที่ระบบบังคับ (ห้ามเชื่อ client)

const UPLOAD_DOC_MIMES = [
    'application/pdf'                                                            => 'pdf',
    'application/msword'                                                         => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
    'application/vnd.ms-excel'                                                   => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'          => 'xlsx',
    'application/vnd.ms-powerpoint'                                              => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'  => 'pptx',
];

const UPLOAD_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

const UPLOAD_CERT_MIMES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

function mb_to_bytes(int $mb): int { return $mb * 1024 * 1024; }

function validate_uploaded_file(array $file, array $allowed_mimes, int $max_size_bytes): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'ข้อมูลไฟล์ไม่ถูกต้อง'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => 'ไม่ได้เลือกไฟล์'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์อนุญาต'];
        case UPLOAD_ERR_PARTIAL:
            return ['ok' => false, 'error' => 'อัปโหลดไม่สมบูรณ์'];
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return ['ok' => false, 'error' => 'เซิร์ฟเวอร์เขียนไฟล์ไม่ได้'];
        default:
            return ['ok' => false, 'error' => 'อัปโหลดไม่สำเร็จ (code ' . (int)$file['error'] . ')'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'ไฟล์ไม่ปลอดภัย'];
    }

    $size = (int) $file['size'];
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'ไฟล์ว่างเปล่า'];
    }
    if ($size > $max_size_bytes) {
        $max_mb = round($max_size_bytes / 1024 / 1024, 1);
        return ['ok' => false, 'error' => 'ไฟล์ใหญ่เกินที่กำหนด (สูงสุด ' . $max_mb . ' MB)'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($mime === false) {
        return ['ok' => false, 'error' => 'ตรวจสอบประเภทไฟล์ไม่สำเร็จ'];
    }

    if (!isset($allowed_mimes[$mime])) {
        return ['ok' => false, 'error' => 'ประเภทไฟล์ไม่อนุญาต (' . $mime . ')'];
    }

    return [
        'ok'   => true,
        'mime' => $mime,
        'ext'  => $allowed_mimes[$mime],
        'size' => $size,
    ];
}

function move_uploaded_with_uuid(array $file, string $subdir, string $ext): string
{
    if (!preg_match('/^[a-z]+$/', $subdir)) {
        throw new InvalidArgumentException('Invalid upload subdir');
    }
    if (!preg_match('/^[a-z0-9]+$/', $ext)) {
        throw new InvalidArgumentException('Invalid file extension');
    }

    $dest_dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dest_dir)) {
        if (!mkdir($dest_dir, 0750, true) && !is_dir($dest_dir)) {
            throw new RuntimeException('Cannot create upload dir: ' . $dest_dir);
        }
    }

    $filename  = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest_path = $dest_dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        throw new RuntimeException('Cannot move uploaded file to ' . $dest_path);
    }
    @chmod($dest_path, 0640);

    return $filename;
}

function safe_unlink_upload(string $subdir, string $filename): bool
{
    if (!preg_match('/^[a-z]+$/', $subdir)) return false;

    $filename = basename($filename);
    if ($filename === '' || $filename === '.' || $filename === '..') return false;

    $base_dir = realpath(UPLOAD_PATH . '/' . $subdir);
    if ($base_dir === false) return false;

    $target = $base_dir . DIRECTORY_SEPARATOR . $filename;
    $real   = realpath($target);
    if ($real === false) return false;

    if (strpos($real, $base_dir . DIRECTORY_SEPARATOR) !== 0
        && $real !== $base_dir) {
        return false;
    }

    return @unlink($real);
}

function sanitize_download_filename(string $name): string
{
    $name = basename($name);
    $name = str_replace(["\r", "\n", "\0", '"'], '', $name);
    return trim($name) !== '' ? $name : 'download';
}
