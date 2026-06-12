<?php

function saveFeedbackImage(array $file, int $userId, string $uploadDir): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => '图片上传失败，请重试'];
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return ['path' => null, 'error' => '图片大小不能超过 10MB'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => '无效的上传文件'];
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        return ['path' => null, 'error' => '无法识别图片格式，请上传 jpg / png 图片'];
    }

    $extMap = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
    ];
    if (!isset($extMap[$imgInfo[2]])) {
        return ['path' => null, 'error' => '仅支持 jpg / jpeg / png 格式'];
    }
    $ext = $extMap[$imgInfo[2]];

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMime = ['image/jpeg', 'image/png', 'image/pjpeg', 'image/x-png'];
        if (!in_array($mime, $allowedMime, true)) {
            // Windows 上 finfo 可能返回 application/octet-stream，以 getimagesize 为准
            if (!isset($extMap[$imgInfo[2]])) {
                return ['path' => null, 'error' => '图片格式校验失败'];
            }
        }
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'error' => '无法创建上传目录'];
    }

    $fileName = 'fb_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadDir), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['path' => null, 'error' => '图片保存失败，请检查 uploads/feedback 目录写入权限'];
    }

    return ['path' => 'uploads/feedback/' . $fileName, 'error' => null];
}

function deleteFeedbackImage(?string $relativePath, string $baseDir): void
{
    if (!$relativePath) {
        return;
    }
    $fullPath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
