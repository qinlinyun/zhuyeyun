<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_broadcast.php';

const ANNOUNCEMENT_CONFIG_KEY = 'announcement_config';

const ANNOUNCEMENT_FREQ_ALWAYS = 'always';
const ANNOUNCEMENT_FREQ_UNREAD = 'unread';

function defaultAnnouncementHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:560px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 12px;font-size:20px;">{{title}}</h2>
  <div style="font-size:14px;color:#374151;">
    {{content}}
  </div>
  <p style="margin:16px 0 0;font-size:12px;color:#9ca3af;">{{site_name}}</p>
</div>
HTML;
}

function defaultAnnouncementConfig(): array
{
    return [
        'html_template' => defaultAnnouncementHtmlTemplate(),
        'mail_subject_prefix' => '【竹叶云控】公告：',
    ];
}

function defaultAnnouncementStyleDefaults(): array
{
    return [
        'title_font_size' => '20px',
        'title_color' => '#111827',
        'content_font_size' => '14px',
        'content_color' => '#374151',
    ];
}

function normalizeAnnouncementFontSize(string $value, string $fallback = '14px'): string
{
    $value = trim($value);
    if (preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/i', $value)) {
        return strtolower($value);
    }

    return $fallback;
}

function normalizeAnnouncementColor(string $value, string $fallback = '#374151'): string
{
    $value = trim($value);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
        return $value;
    }
    if (preg_match('/^rgb(a)?\(/i', $value)) {
        return $value;
    }

    return $fallback;
}

function announcementResolveStyles(array $announcement): array
{
    $defaults = defaultAnnouncementStyleDefaults();

    return [
        'title_font_size' => normalizeAnnouncementFontSize(
            (string)($announcement['title_font_size'] ?? ''),
            $defaults['title_font_size']
        ),
        'title_color' => normalizeAnnouncementColor(
            (string)($announcement['title_color'] ?? ''),
            $defaults['title_color']
        ),
        'content_font_size' => normalizeAnnouncementFontSize(
            (string)($announcement['content_font_size'] ?? ''),
            $defaults['content_font_size']
        ),
        'content_color' => normalizeAnnouncementColor(
            (string)($announcement['content_color'] ?? ''),
            $defaults['content_color']
        ),
    ];
}

function parseAnnouncementStyleFromPost(array $post): array
{
    $defaults = defaultAnnouncementStyleDefaults();

    return [
        'title_font_size' => normalizeAnnouncementFontSize(
            (string)($post['announcement_title_font_size'] ?? ''),
            $defaults['title_font_size']
        ),
        'title_color' => normalizeAnnouncementColor(
            (string)($post['announcement_title_color'] ?? ''),
            $defaults['title_color']
        ),
        'content_font_size' => normalizeAnnouncementFontSize(
            (string)($post['announcement_content_font_size'] ?? ''),
            $defaults['content_font_size']
        ),
        'content_color' => normalizeAnnouncementColor(
            (string)($post['announcement_content_color'] ?? ''),
            $defaults['content_color']
        ),
    ];
}

function normalizeAnnouncementConfig(array $data): array
{
    $defaults = defaultAnnouncementConfig();
    $template = trim((string)($data['html_template'] ?? $defaults['html_template']));
    if ($template === '') {
        $template = $defaults['html_template'];
    }

    $prefix = trim((string)($data['mail_subject_prefix'] ?? $defaults['mail_subject_prefix']));
    if ($prefix === '') {
        $prefix = $defaults['mail_subject_prefix'];
    }

    return [
        'html_template' => $template,
        'mail_subject_prefix' => $prefix,
    ];
}

function getAnnouncementConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, ANNOUNCEMENT_CONFIG_KEY, '');
    if ($raw === '') {
        return defaultAnnouncementConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeAnnouncementConfig($data) : defaultAnnouncementConfig();
}

function saveAnnouncementConfig(PDO $pdo, array $config): void
{
    setSetting(
        $pdo,
        ANNOUNCEMENT_CONFIG_KEY,
        json_encode(normalizeAnnouncementConfig($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseAnnouncementConfigFromPost(array $post): array
{
    return normalizeAnnouncementConfig([
        'html_template' => $post['announcement_html_template'] ?? '',
        'mail_subject_prefix' => $post['announcement_mail_subject_prefix'] ?? '',
    ]);
}

function announcementConfigValidationError(array $config): ?string
{
    if (strpos($config['html_template'], '{{content}}') === false) {
        return '公告模板中必须包含 {{content}} 占位符';
    }
    if (strpos($config['html_template'], '{{title}}') === false) {
        return '公告模板中必须包含 {{title}} 占位符';
    }

    return null;
}

function ensureAnnouncementTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_announcements` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(200) NOT NULL,
        `content` mediumtext NOT NULL,
        `popup_frequency` enum('always','unread') NOT NULL DEFAULT 'unread',
        `email_notify` tinyint(1) NOT NULL DEFAULT 0,
        `status` enum('draft','published') NOT NULL DEFAULT 'draft',
        `notification_id` bigint unsigned DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `published_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_status_published` (`status`,`published_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_announcement_reads` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `announcement_id` bigint unsigned NOT NULL,
        `user_id` int(11) NOT NULL,
        `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_ann_user` (`announcement_id`,`user_id`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $styleColumns = [
        'title_font_size' => "varchar(20) NOT NULL DEFAULT '20px'",
        'title_color' => "varchar(32) NOT NULL DEFAULT '#111827'",
        'content_font_size' => "varchar(20) NOT NULL DEFAULT '14px'",
        'content_color' => "varchar(32) NOT NULL DEFAULT '#374151'",
    ];
    foreach ($styleColumns as $column => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM site_announcements LIKE " . $pdo->quote($column));
        if (!$check || !$check->fetch()) {
            $pdo->exec("ALTER TABLE site_announcements ADD COLUMN `{$column}` {$definition} AFTER `content`");
        }
    }
}

function announcementFrequencyOptions(): array
{
    return [
        ANNOUNCEMENT_FREQ_ALWAYS => '每次访问首页都弹出',
        ANNOUNCEMENT_FREQ_UNREAD => '公告未读时才弹出',
    ];
}

function listAnnouncements(PDO $pdo, int $limit = 100): array
{
    ensureAnnouncementTables($pdo);
    $stmt = $pdo->prepare("
        SELECT a.*, u.username AS creator_name
        FROM site_announcements a
        LEFT JOIN users u ON u.id = a.created_by
        ORDER BY a.updated_at DESC, a.id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getAnnouncementById(PDO $pdo, int $id): ?array
{
    ensureAnnouncementTables($pdo);
    $stmt = $pdo->prepare("SELECT * FROM site_announcements WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function renderAnnouncementHtml(PDO $pdo, array $announcement): string
{
    $cfg = getAnnouncementConfig($pdo);
    $smtp = function_exists('getMailSmtpConfig') ? getMailSmtpConfig($pdo) : ['from_name' => '竹叶云控平台'];
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));
    $styles = announcementResolveStyles($announcement);

    $titleText = htmlspecialchars((string)($announcement['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $contentHtml = (string)($announcement['content'] ?? '');
    $styleDefaults = defaultAnnouncementStyleDefaults();

    $titleRendered = $titleText;
    if ($styles['title_font_size'] !== $styleDefaults['title_font_size']
        || $styles['title_color'] !== $styleDefaults['title_color']) {
        $titleRendered = sprintf(
            '<span style="font-size:%s;color:%s;">%s</span>',
            $styles['title_font_size'],
            $styles['title_color'],
            $titleText
        );
    }

    $contentRendered = $contentHtml;
    if ($styles['content_font_size'] !== $styleDefaults['content_font_size']
        || $styles['content_color'] !== $styleDefaults['content_color']) {
        $contentRendered = sprintf(
            '<div style="font-size:%s;color:%s;">%s</div>',
            $styles['content_font_size'],
            $styles['content_color'],
            $contentHtml
        );
    }

    $replacements = [
        '{{title}}' => $titleRendered,
        '{{content}}' => $contentRendered,
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
        '{{title_font_size}}' => $styles['title_font_size'],
        '{{title_color}}' => $styles['title_color'],
        '{{content_font_size}}' => $styles['content_font_size'],
        '{{content_color}}' => $styles['content_color'],
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $cfg['html_template']);
}

function userHasReadAnnouncement(PDO $pdo, int $userId, int $announcementId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM site_announcement_reads WHERE announcement_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$announcementId, $userId]);

    return (bool)$stmt->fetchColumn();
}

function markAnnouncementRead(PDO $pdo, int $userId, int $announcementId): void
{
    ensureAnnouncementTables($pdo);
    $stmt = $pdo->prepare('INSERT IGNORE INTO site_announcement_reads (announcement_id, user_id, read_at) VALUES (?, ?, NOW())');
    $stmt->execute([$announcementId, $userId]);
}

function getHomepageAnnouncementForUser(PDO $pdo, int $userId): ?array
{
    ensureAnnouncementTables($pdo);
    $stmt = $pdo->query("
        SELECT *
        FROM site_announcements
        WHERE status = 'published'
        ORDER BY published_at DESC, id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $freq = (string)($row['popup_frequency'] ?? ANNOUNCEMENT_FREQ_UNREAD);
        if ($freq === ANNOUNCEMENT_FREQ_ALWAYS) {
            return $row;
        }
        if (!userHasReadAnnouncement($pdo, $userId, (int)$row['id'])) {
            return $row;
        }
    }

    return null;
}

function syncAnnouncementNotification(PDO $pdo, array $announcement, int $adminUserId): int
{
    $title = trim((string)($announcement['title'] ?? ''));
    $content = trim((string)($announcement['content'] ?? ''));
    $notificationId = (int)($announcement['notification_id'] ?? 0);

    if ($notificationId > 0) {
        $stmt = $pdo->prepare('UPDATE notifications SET title = ?, content = ? WHERE id = ?');
        $stmt->execute([$title, $content, $notificationId]);

        return $notificationId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (title, content, target_type, target_user_id, created_by)
        VALUES (?, ?, 'all', NULL, ?)
    ");
    $stmt->execute([$title, $content, $adminUserId]);

    return (int)$pdo->lastInsertId();
}

function removeAnnouncementNotification(PDO $pdo, int $notificationId): void
{
    if ($notificationId <= 0) {
        return;
    }

    $pdo->prepare('DELETE FROM notification_reads WHERE notification_id = ?')->execute([$notificationId]);
    $pdo->prepare('DELETE FROM notifications WHERE id = ?')->execute([$notificationId]);
}

function triggerAnnouncementMailBroadcast(PDO $pdo, array $announcement): array
{
    if (empty($announcement['email_notify'])) {
        return ['ok' => true, 'message' => '未开启邮件通知'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '邮局未配置，无法发送邮件通知'];
    }

    $cfg = getAnnouncementConfig($pdo);
    $broadcastBase = getMailBroadcastConfig($pdo);
    $subject = trim($cfg['mail_subject_prefix'] . (string)($announcement['title'] ?? ''));

    $override = normalizeMailBroadcastConfig([
        'subject' => $subject,
        'html_template' => $broadcastBase['html_template'],
        'content' => (string)($announcement['content'] ?? ''),
        'batch_size' => $broadcastBase['batch_size'],
        'batch_pause_minutes' => $broadcastBase['batch_pause_minutes'],
    ]);

    return startMailBroadcastJob($pdo, $override);
}

/**
 * @return array{ok:bool,message?:string,id?:int}
 */
function saveAnnouncementFromPost(PDO $pdo, array $post, int $adminUserId): array
{
    ensureAnnouncementTables($pdo);

    $id = (int)($post['announcement_id'] ?? 0);
    $title = trim((string)($post['announcement_title'] ?? ''));
    $content = trim((string)($post['announcement_content'] ?? ''));
    $frequency = ($post['announcement_popup_frequency'] ?? ANNOUNCEMENT_FREQ_UNREAD) === ANNOUNCEMENT_FREQ_ALWAYS
        ? ANNOUNCEMENT_FREQ_ALWAYS
        : ANNOUNCEMENT_FREQ_UNREAD;
    $emailNotify = !empty($post['announcement_email_notify']);
    $status = ($post['announcement_status'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $styles = parseAnnouncementStyleFromPost($post);

    if ($title === '') {
        return ['ok' => false, 'message' => '请填写公告标题'];
    }
    if ($content === '') {
        return ['ok' => false, 'message' => '请填写公告内容'];
    }

    $existing = $id > 0 ? getAnnouncementById($pdo, $id) : null;
    $wasPublished = $existing && ($existing['status'] ?? '') === 'published';
    $notificationId = (int)($existing['notification_id'] ?? 0);

    if ($status === 'published') {
        $draftRow = [
            'title' => $title,
            'content' => $content,
            'notification_id' => $notificationId,
        ];
        $notificationId = syncAnnouncementNotification($pdo, $draftRow, $adminUserId);
    } elseif ($existing && $notificationId > 0) {
        removeAnnouncementNotification($pdo, $notificationId);
        $notificationId = 0;
    }

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE site_announcements
            SET title = ?, content = ?, title_font_size = ?, title_color = ?,
                content_font_size = ?, content_color = ?,
                popup_frequency = ?, email_notify = ?, status = ?,
                notification_id = ?, published_at = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $publishedAt = $status === 'published'
            ? ($existing['published_at'] ?: date('Y-m-d H:i:s'))
            : null;
        if ($status === 'published' && !$wasPublished) {
            $publishedAt = date('Y-m-d H:i:s');
        }
        $stmt->execute([
            $title,
            $content,
            $styles['title_font_size'],
            $styles['title_color'],
            $styles['content_font_size'],
            $styles['content_color'],
            $frequency,
            $emailNotify ? 1 : 0,
            $status,
            $notificationId ?: null,
            $publishedAt,
            $id,
        ]);
        $savedId = $id;
        $contentChanged = ($existing['title'] ?? '') !== $title
            || ($existing['content'] ?? '') !== $content
            || ($existing['title_font_size'] ?? '') !== $styles['title_font_size']
            || ($existing['title_color'] ?? '') !== $styles['title_color']
            || ($existing['content_font_size'] ?? '') !== $styles['content_font_size']
            || ($existing['content_color'] ?? '') !== $styles['content_color'];
        if ($status === 'published' && $wasPublished && $contentChanged) {
            $pdo->prepare('DELETE FROM site_announcement_reads WHERE announcement_id = ?')->execute([$id]);
            if ($notificationId > 0) {
                $pdo->prepare('DELETE FROM notification_reads WHERE notification_id = ?')->execute([$notificationId]);
            }
        }
    } else {
        $publishedAt = $status === 'published' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare("
            INSERT INTO site_announcements
            (title, content, title_font_size, title_color, content_font_size, content_color,
             popup_frequency, email_notify, status, notification_id, created_by, published_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $title,
            $content,
            $styles['title_font_size'],
            $styles['title_color'],
            $styles['content_font_size'],
            $styles['content_color'],
            $frequency,
            $emailNotify ? 1 : 0,
            $status,
            $notificationId ?: null,
            $adminUserId,
            $publishedAt,
        ]);
        $savedId = (int)$pdo->lastInsertId();
    }

    $saved = getAnnouncementById($pdo, $savedId);
    $mailMessage = '';
    if ($saved && $status === 'published' && $emailNotify && !$wasPublished) {
        $mailResult = triggerAnnouncementMailBroadcast($pdo, $saved);
        $mailMessage = !empty($mailResult['ok'])
            ? '，邮件全员通知任务已启动'
            : '（邮件通知未启动：' . ($mailResult['message'] ?? '失败') . '）';
    }

    return [
        'ok' => true,
        'message' => ($status === 'published' ? '公告已发布并同步站内通知' : '公告已保存') . $mailMessage,
        'id' => $savedId,
    ];
}

/**
 * @return array{ok:bool,message?:string}
 */
function deleteAnnouncement(PDO $pdo, int $id): array
{
    $row = getAnnouncementById($pdo, $id);
    if (!$row) {
        return ['ok' => false, 'message' => '公告不存在'];
    }

    removeAnnouncementNotification($pdo, (int)($row['notification_id'] ?? 0));
    $pdo->prepare('DELETE FROM site_announcement_reads WHERE announcement_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM site_announcements WHERE id = ?')->execute([$id]);

    return ['ok' => true, 'message' => '公告已删除，相关站内通知已同步移除'];
}

function parseAnnouncementFromPost(array $post): array
{
    return [
        'id' => (int)($post['announcement_id'] ?? 0),
        'title' => trim((string)($post['announcement_title'] ?? '')),
        'content' => (string)($post['announcement_content'] ?? ''),
        'popup_frequency' => ($post['announcement_popup_frequency'] ?? ANNOUNCEMENT_FREQ_UNREAD) === ANNOUNCEMENT_FREQ_ALWAYS
            ? ANNOUNCEMENT_FREQ_ALWAYS
            : ANNOUNCEMENT_FREQ_UNREAD,
        'email_notify' => !empty($post['announcement_email_notify']),
        'status' => ($post['announcement_status'] ?? 'draft') === 'published' ? 'published' : 'draft',
    ];
}
