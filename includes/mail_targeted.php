<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_sender.php';

const MAIL_TARGETED_TEMPLATES_KEY = 'mail_targeted_templates';

function defaultMailTargetedHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:560px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 通知</h2>
  <p style="margin:0 0 8px;">尊敬的 {{username}}（{{email}}）：</p>
  <div style="margin:16px 0;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
    {{content}}
  </div>
  <p style="margin:16px 0 0;font-size:13px;color:#6b7280;">此邮件为系统自动发送，请勿直接回复。</p>
</div>
HTML;
}

function mailTargetedStrSub(string $s, int $start, ?int $len = null): string
{
    if (function_exists('mb_substr')) {
        return (string)mb_substr($s, $start, $len, 'UTF-8');
    }
    if ($len === null) {
        return substr($s, $start);
    }
    return substr($s, $start, $len);
}

function mailTargetedTemplateValidationError(string $htmlTemplate): ?string
{
    $htmlTemplate = trim($htmlTemplate);
    if ($htmlTemplate === '') {
        return '请填写 HTML 邮件模板代码';
    }
    if (strpos($htmlTemplate, '{{content}}') === false) {
        return '邮件模板中必须包含 {{content}} 占位符';
    }
    return null;
}

/**
 * @param array $tpl array{id?:mixed,name?:mixed,html_template?:mixed,updated_at?:mixed}
 * @return array{id:string,name:string,html_template:string,updated_at:string}
 */
function normalizeMailTargetedTemplate(array $tpl): array
{
    $id = trim((string)($tpl['id'] ?? ''));
    if ($id === '') {
        $id = 'tpl_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    $name = trim((string)($tpl['name'] ?? ''));
    if ($name === '') {
        $name = '未命名模板';
    }
    $name = mailTargetedStrSub($name, 0, 80);

    $html = (string)($tpl['html_template'] ?? '');
    if ($html === '') {
        $html = defaultMailTargetedHtmlTemplate();
    }
    if (strlen($html) > 200000) {
        $html = substr($html, 0, 200000);
    }

    $updatedAt = trim((string)($tpl['updated_at'] ?? ''));
    if ($updatedAt === '') {
        $updatedAt = date('Y-m-d H:i:s');
    }

    return [
        'id' => $id,
        'name' => $name,
        'html_template' => $html,
        'updated_at' => $updatedAt,
    ];
}

/**
 * @return array<int,array{id:string,name:string,html_template:string,updated_at:string}>
 */
function getMailTargetedTemplates(PDO $pdo): array
{
    $raw = getSetting($pdo, MAIL_TARGETED_TEMPLATES_KEY, '');
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $templates = [];
    foreach ($data as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $templates[] = normalizeMailTargetedTemplate($tpl);
    }

    usort($templates, static function (array $a, array $b): int {
        return strcmp((string)$b['updated_at'], (string)$a['updated_at']);
    });

    return $templates;
}

/**
 * @param array<int,array{id:string,name:string,html_template:string,updated_at:string}> $templates
 */
function saveMailTargetedTemplates(PDO $pdo, array $templates): void
{
    $normalized = [];
    $seen = [];
    foreach ($templates as $tpl) {
        if (!is_array($tpl)) {
            continue;
        }
        $t = normalizeMailTargetedTemplate($tpl);
        if (isset($seen[$t['id']])) {
            continue;
        }
        $seen[$t['id']] = true;
        $normalized[] = $t;
    }

    // 防止无限膨胀：最多保留 50 个
    $normalized = array_slice($normalized, 0, 50);

    setSetting(
        $pdo,
        MAIL_TARGETED_TEMPLATES_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * @return array{ok:bool,message?:string,template?:array{id:string,name:string,html_template:string,updated_at:string}}
 */
function upsertMailTargetedTemplate(PDO $pdo, string $id, string $name, string $htmlTemplate): array
{
    $id = trim($id);
    $name = trim($name);
    $htmlTemplate = (string)$htmlTemplate;

    if ($name === '') {
        return ['ok' => false, 'message' => '请填写模板名称'];
    }
    if ($err = mailTargetedTemplateValidationError($htmlTemplate)) {
        return ['ok' => false, 'message' => $err];
    }

    $templates = getMailTargetedTemplates($pdo);
    $found = false;
    $template = normalizeMailTargetedTemplate([
        'id' => $id,
        'name' => $name,
        'html_template' => $htmlTemplate,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    if ($id === '') {
        $template = normalizeMailTargetedTemplate([
            'name' => $name,
            'html_template' => $htmlTemplate,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        array_unshift($templates, $template);
        saveMailTargetedTemplates($pdo, $templates);
        return ['ok' => true, 'template' => $template];
    }

    foreach ($templates as $idx => $tpl) {
        if (($tpl['id'] ?? '') === $id) {
            $templates[$idx] = $template;
            $found = true;
            break;
        }
    }
    if (!$found) {
        array_unshift($templates, $template);
    }

    saveMailTargetedTemplates($pdo, $templates);
    return ['ok' => true, 'template' => $template];
}

/**
 * @return array{ok:bool,message?:string}
 */
function deleteMailTargetedTemplate(PDO $pdo, string $id): array
{
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'message' => '模板 ID 无效'];
    }

    $templates = getMailTargetedTemplates($pdo);
    $before = count($templates);
    $templates = array_values(array_filter($templates, static fn(array $tpl): bool => (string)($tpl['id'] ?? '') !== $id));
    if (count($templates) === $before) {
        return ['ok' => false, 'message' => '模板不存在或已删除'];
    }

    saveMailTargetedTemplates($pdo, $templates);
    return ['ok' => true];
}

function renderMailTargetedEmailHtml(PDO $pdo, string $htmlTemplate, string $contentHtml, array $user, string $subject = ''): string
{
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));

    $username = (string)($user['display_name'] ?? '');
    if (trim($username) === '') {
        $username = (string)($user['username'] ?? '');
    }

    $replacements = [
        '{{username}}' => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
        '{{display_name}}' => htmlspecialchars((string)($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{content}}' => (string)$contentHtml,
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
        '{{subject}}' => htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $htmlTemplate);
}

/**
 * @return array{items: list<array{id:int,username:string,display_name:?string,email:string,status:string,group_id:int}>,total:int,page:int,pages:int,per_page:int,range_start:int,range_end:int}
 */
function listMailTargetedUsers(PDO $pdo, string $query, int $page = 1, int $perPage = 10): array
{
    $query = trim($query);
    $page = max(1, $page);
    $perPage = normalizeMailTargetedUserPageSize($perPage);

    $where = '';
    $params = [];
    if ($query !== '') {
        $where = 'WHERE username LIKE ? OR email LIKE ? OR display_name LIKE ?';
        $like = '%' . $query . '%';
        $params = [$like, $like, $like];
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users ' . $where);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pages = max(1, (int)ceil(max(0, $total) / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = '
        SELECT id, username, display_name, email, status, group_id
        FROM users
        ' . $where . '
        ORDER BY id DESC
        LIMIT ? OFFSET ?';
    $stmt = $pdo->prepare($sql);
    $bindIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($bindIndex++, $param);
    }
    $stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rangeStart = $total > 0 ? $offset + 1 : 0;
    $rangeEnd = min($total, $offset + count($items));

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd,
    ];
}

/** @return list<int> */
function mailTargetedUserPageSizeOptions(): array
{
    return [10, 20, 30, 50];
}

function normalizeMailTargetedUserPageSize(int $size): int
{
    return in_array($size, mailTargetedUserPageSizeOptions(), true) ? $size : 10;
}

/**
 * @return array<int,array{id:int,username:string,display_name:?string,email:string,status:string,group_id:int}>
 */
function searchMailTargetedUsers(PDO $pdo, string $query, int $limit = 20): array
{
    $result = listMailTargetedUsers($pdo, $query, 1, min(50, max(1, $limit)));

    return $result['items'];
}

/**
 * @param array<int|string> $userIds
 * @return array<int,array{id:int,username:string,display_name:?string,email:string,status:string}>
 */
function fetchMailTargetedUsersByIds(PDO $pdo, array $userIds): array
{
    $ids = [];
    foreach ($userIds as $id) {
        $v = (int)$id;
        if ($v > 0) {
            $ids[$v] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        return [];
    }
    $ids = array_slice($ids, 0, 500);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, username, display_name, email, status
        FROM users
        WHERE id IN ($placeholders)
        ORDER BY id DESC
    ");
    foreach ($ids as $idx => $id) {
        $stmt->bindValue($idx + 1, (int)$id, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int|string> $userIds
 * @return array{ok:bool,message?:string,stats?:array{total:int,attempted:int,sent:int,failed:int,invalid:int,failures?:array<int,array{user_id:int,email:string,message:string}>}}
 */
function sendMailTargetedToUsers(PDO $pdo, array $userIds, string $subject, string $contentHtml, ?string $templateId = null): array
{
    $subject = trim($subject);
    $contentHtml = trim($contentHtml);
    $templateId = $templateId !== null ? trim($templateId) : null;

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置并启用'];
    }
    if ($subject === '') {
        return ['ok' => false, 'message' => '请填写邮件主题'];
    }
    if ($contentHtml === '') {
        return ['ok' => false, 'message' => '请填写发送内容（HTML）'];
    }

    $users = fetchMailTargetedUsersByIds($pdo, $userIds);
    if ($users === []) {
        return ['ok' => false, 'message' => '请先选择至少 1 个用户'];
    }

    $templateHtml = null;
    if ($templateId !== null && $templateId !== '') {
        foreach (getMailTargetedTemplates($pdo) as $tpl) {
            if (($tpl['id'] ?? '') === $templateId) {
                $templateHtml = (string)($tpl['html_template'] ?? '');
                break;
            }
        }
        if ($templateHtml === null) {
            return ['ok' => false, 'message' => '所选邮件模板不存在或已删除'];
        }
        if ($err = mailTargetedTemplateValidationError($templateHtml)) {
            return ['ok' => false, 'message' => '所选邮件模板无效：' . $err];
        }
    }

    $stats = [
        'total' => count($users),
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'invalid' => 0,
        'failures' => [],
    ];

    foreach ($users as $user) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stats['invalid']++;
            continue;
        }

        $stats['attempted']++;
        $body = $templateHtml !== null
            ? renderMailTargetedEmailHtml($pdo, $templateHtml, $contentHtml, $user, $subject)
            : $contentHtml;

        $result = sendSiteMail($pdo, $email, $subject, $body, true);
        if (!empty($result['ok'])) {
            $stats['sent']++;
            continue;
        }

        $stats['failed']++;
        if (count($stats['failures']) < 10) {
            $stats['failures'][] = [
                'user_id' => (int)($user['id'] ?? 0),
                'email' => $email,
                'message' => (string)($result['message'] ?? '发送失败'),
            ];
        }
    }

    $ok = $stats['sent'] > 0 && $stats['failed'] === 0;
    if ($stats['attempted'] <= 0) {
        return ['ok' => false, 'message' => '所选用户没有可用的有效邮箱', 'stats' => $stats];
    }

    $summary = '指定通知发送完成：成功 ' . $stats['sent']
        . '，失败 ' . $stats['failed']
        . '，无效邮箱 ' . $stats['invalid']
        . '（共选择 ' . $stats['total'] . '）';

    return ['ok' => $ok, 'message' => $summary, 'stats' => $stats];
}

