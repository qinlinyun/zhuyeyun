<?php

/**
 * 规范化并确保本地上传目录存在且可写
 *
 * @return array{ok: bool, path: string, message: string}
 */
function ensureUploadDirectory(string $dir): array
{
    $dir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir), DIRECTORY_SEPARATOR);
    if ($dir === '') {
        return ['ok' => false, 'path' => '', 'message' => '上传目录路径未配置'];
    }
    $dir .= DIRECTORY_SEPARATOR;

    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            return [
                'ok' => false,
                'path' => $dir,
                'message' => '上传目录不可写，请在服务器执行：chown -R www:www ' . $dir . ' && chmod -R 755 ' . $dir,
            ];
        }
        return ['ok' => true, 'path' => $dir, 'message' => ''];
    }

    if (file_exists($dir)) {
        return ['ok' => false, 'path' => $dir, 'message' => '上传路径已存在但不是目录: ' . $dir];
    }

    $parent = dirname($dir);
    if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => '无法创建上传目录，请手动创建并授权：mkdir -p ' . $dir . ' && chown -R www:www ' . dirname($dir),
        ];
    }

    if (!@mkdir($dir, 0755, true)) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => '无法创建上传目录（Permission denied），请手动创建：' . $dir . ' 并设置 Web 用户可写',
        ];
    }

    if (!is_writable($dir)) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => '上传目录已创建但不可写: ' . $dir,
        ];
    }

    return ['ok' => true, 'path' => $dir, 'message' => ''];
}

/**
 * 创建单次上传子目录
 *
 * @return array{ok: bool, message: string}
 */
function ensureUploadSubDirectory(string $path): array
{
    if (is_dir($path)) {
        return is_writable($path)
            ? ['ok' => true, 'message' => '']
            : ['ok' => false, 'message' => '上传子目录不可写: ' . $path];
    }

    if (!@mkdir($path, 0755, true)) {
        return ['ok' => false, 'message' => '无法创建上传子目录: ' . $path];
    }

    return ['ok' => true, 'message' => ''];
}
