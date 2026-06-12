<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_sender.php';

const MAIL_BROADCAST_CONFIG_KEY = 'mail_broadcast_config';
const MAIL_BROADCAST_JOB_KEY = 'mail_broadcast_job';

function defaultMailBroadcastHtmlTemplate(): string
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

function defaultMailBroadcastConfig(): array
{
    return [
        'subject' => '【竹叶云控】全员通知',
        'html_template' => defaultMailBroadcastHtmlTemplate(),
        'content' => '<p>您好，这是一条全员通知示例内容。</p>',
        'batch_size' => 20,
        'batch_pause_minutes' => 5,
    ];
}

function normalizeMailBroadcastConfig(array $data): array
{
    $defaults = defaultMailBroadcastConfig();

    $batchSize = (int)($data['batch_size'] ?? $defaults['batch_size']);
    $pauseMin = (int)($data['batch_pause_minutes'] ?? $defaults['batch_pause_minutes']);

    $template = trim((string)($data['html_template'] ?? $defaults['html_template']));
    if ($template === '') {
        $template = $defaults['html_template'];
    }

    $subject = trim((string)($data['subject'] ?? $defaults['subject']));
    if ($subject === '') {
        $subject = $defaults['subject'];
    }

    $content = trim((string)($data['content'] ?? $defaults['content']));
    if ($content === '') {
        $content = $defaults['content'];
    }

    return [
        'subject' => $subject,
        'html_template' => $template,
        'content' => $content,
        'batch_size' => max(1, min(500, $batchSize > 0 ? $batchSize : 20)),
        'batch_pause_minutes' => max(0, min(1440, $pauseMin >= 0 ? $pauseMin : 5)),
    ];
}

function getMailBroadcastConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, MAIL_BROADCAST_CONFIG_KEY, '');
    if ($raw === '') {
        return defaultMailBroadcastConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeMailBroadcastConfig($data) : defaultMailBroadcastConfig();
}

function saveMailBroadcastConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeMailBroadcastConfig($config);
    setSetting(
        $pdo,
        MAIL_BROADCAST_CONFIG_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseMailBroadcastConfigFromPost(array $post): array
{
    return normalizeMailBroadcastConfig([
        'subject' => $post['broadcast_subject'] ?? '',
        'html_template' => $post['broadcast_template'] ?? '',
        'content' => $post['broadcast_content'] ?? '',
        'batch_size' => $post['broadcast_batch_size'] ?? 20,
        'batch_pause_minutes' => $post['broadcast_batch_pause_minutes'] ?? 5,
    ]);
}

function mailBroadcastConfigValidationError(array $config): ?string
{
    if (strpos($config['html_template'], '{{content}}') === false) {
        return '邮件模板中必须包含 {{content}} 占位符';
    }
    if ($config['subject'] === '') {
        return '请填写邮件主题';
    }
    if ($config['content'] === '') {
        return '请填写发送内容';
    }

    return null;
}

function getMailBroadcastJob(PDO $pdo): ?array
{
    $raw = getSetting($pdo, MAIL_BROADCAST_JOB_KEY, '');
    if ($raw === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function saveMailBroadcastJob(PDO $pdo, ?array $job): void
{
    if ($job === null) {
        setSetting($pdo, MAIL_BROADCAST_JOB_KEY, '');
        return;
    }

    setSetting(
        $pdo,
        MAIL_BROADCAST_JOB_KEY,
        json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function mailBroadcastJobIsActive(?array $job): bool
{
    if (!$job) {
        return false;
    }

    $status = (string)($job['status'] ?? '');

    return in_array($status, ['running', 'paused'], true);
}

function renderMailBroadcastEmailHtml(PDO $pdo, array $config, array $user): string
{
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));

    $replacements = [
        '{{username}}' => htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{content}}' => (string)($config['content'] ?? ''),
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $config['html_template']);
}

function countMailBroadcastRecipients(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email <> '' AND email LIKE '%@%'");

    return (int)$stmt->fetchColumn();
}

function fetchMailBroadcastRecipients(PDO $pdo, int $afterUserId, int $limit): array
{
    $stmt = $pdo->prepare("
        SELECT id, username, email
        FROM users
        WHERE id > ? AND email <> '' AND email LIKE '%@%'
        ORDER BY id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $afterUserId, PDO::PARAM_INT);
    $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message?:string,job?:array}
 */
function startMailBroadcastJob(PDO $pdo, ?array $overrideConfig = null): array
{
    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $existing = getMailBroadcastJob($pdo);
    if (mailBroadcastJobIsActive($existing)) {
        return ['ok' => false, 'message' => '已有进行中的全员通知任务，请等待完成或取消后再试'];
    }

    $config = normalizeMailBroadcastConfig($overrideConfig ?? getMailBroadcastConfig($pdo));
    if ($error = mailBroadcastConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $total = countMailBroadcastRecipients($pdo);
    if ($total <= 0) {
        return ['ok' => false, 'message' => '没有可发送的有效用户邮箱'];
    }

    $job = [
        'status' => 'running',
        'subject' => $config['subject'],
        'html_template' => $config['html_template'],
        'content' => $config['content'],
        'batch_size' => (int)$config['batch_size'],
        'batch_pause_minutes' => (int)$config['batch_pause_minutes'],
        'last_user_id' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'batch_sent_in_cycle' => 0,
        'total_count' => $total,
        'started_at' => date('Y-m-d H:i:s'),
        'pause_until' => null,
        'last_error' => '',
        'finished_at' => null,
    ];

    saveMailBroadcastJob($pdo, $job);

    return ['ok' => true, 'message' => '全员通知任务已创建', 'job' => $job];
}

function cancelMailBroadcastJob(PDO $pdo): void
{
    $job = getMailBroadcastJob($pdo);
    if (!$job) {
        return;
    }

    $job['status'] = 'cancelled';
    $job['finished_at'] = date('Y-m-d H:i:s');
    saveMailBroadcastJob($pdo, $job);
}

/**
 * 执行一步发送（最多处理一个批次内的剩余额度）
 *
 * @return array{ok:bool,message?:string,job?:array,done?:bool,waiting?:bool,wait_seconds?:int}
 */
function processMailBroadcastStep(PDO $pdo): array
{
    $job = getMailBroadcastJob($pdo);
    if (!$job) {
        return ['ok' => false, 'message' => '当前没有进行中的全员通知任务'];
    }

    $status = (string)($job['status'] ?? '');
    if ($status === 'completed') {
        return ['ok' => true, 'message' => '任务已完成', 'job' => $job, 'done' => true];
    }
    if ($status === 'cancelled') {
        return ['ok' => false, 'message' => '任务已取消', 'job' => $job, 'done' => true];
    }

    if ($status === 'paused') {
        $pauseUntil = strtotime((string)($job['pause_until'] ?? ''));
        if ($pauseUntil > time()) {
            return [
                'ok' => true,
                'message' => '批次间歇暂停中',
                'job' => $job,
                'waiting' => true,
                'wait_seconds' => $pauseUntil - time(),
            ];
        }
        $job['status'] = 'running';
        $job['pause_until'] = null;
        $job['batch_sent_in_cycle'] = 0;
    }

    if (!isMailConfigured($pdo)) {
        $job['status'] = 'cancelled';
        $job['last_error'] = 'SMTP 未配置';
        $job['finished_at'] = date('Y-m-d H:i:s');
        saveMailBroadcastJob($pdo, $job);

        return ['ok' => false, 'message' => 'SMTP 未配置，任务已终止', 'job' => $job, 'done' => true];
    }

    $batchSize = max(1, (int)($job['batch_size'] ?? 20));
    $batchSent = (int)($job['batch_sent_in_cycle'] ?? 0);
    $remainingInBatch = $batchSize - $batchSent;
    if ($remainingInBatch <= 0) {
        $remainingInBatch = $batchSize;
        $job['batch_sent_in_cycle'] = 0;
    }

    $config = [
        'subject' => (string)($job['subject'] ?? ''),
        'html_template' => (string)($job['html_template'] ?? ''),
        'content' => (string)($job['content'] ?? ''),
    ];

    $users = fetchMailBroadcastRecipients($pdo, (int)($job['last_user_id'] ?? 0), $remainingInBatch);
    if ($users === []) {
        $job['status'] = 'completed';
        $job['finished_at'] = date('Y-m-d H:i:s');
        $job['pause_until'] = null;
        saveMailBroadcastJob($pdo, $job);

        return ['ok' => true, 'message' => '全员通知发送完成', 'job' => $job, 'done' => true];
    }

    foreach ($users as $user) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $job['last_user_id'] = (int)$user['id'];
            continue;
        }

        $html = renderMailBroadcastEmailHtml($pdo, $config, $user);
        $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);

        $job['last_user_id'] = (int)$user['id'];
        if (!empty($result['ok'])) {
            $job['sent_count'] = (int)$job['sent_count'] + 1;
            $job['batch_sent_in_cycle'] = (int)$job['batch_sent_in_cycle'] + 1;
        } else {
            $job['failed_count'] = (int)$job['failed_count'] + 1;
            $job['last_error'] = (string)($result['message'] ?? '发送失败');
        }
    }

    $processedAll = ((int)$job['sent_count'] + (int)$job['failed_count']) >= (int)$job['total_count']
        || fetchMailBroadcastRecipients($pdo, (int)$job['last_user_id'], 1) === [];

    if ($processedAll) {
        $job['status'] = 'completed';
        $job['finished_at'] = date('Y-m-d H:i:s');
        $job['pause_until'] = null;
        saveMailBroadcastJob($pdo, $job);

        return ['ok' => true, 'message' => '全员通知发送完成', 'job' => $job, 'done' => true];
    }

    if ((int)$job['batch_sent_in_cycle'] >= $batchSize) {
        $pauseMinutes = max(0, (int)($job['batch_pause_minutes'] ?? 0));
        if ($pauseMinutes > 0) {
            $job['status'] = 'paused';
            $job['pause_until'] = date('Y-m-d H:i:s', time() + $pauseMinutes * 60);
            saveMailBroadcastJob($pdo, $job);

            return [
                'ok' => true,
                'message' => '本批次已发送 ' . $batchSize . ' 封，暂停 ' . $pauseMinutes . ' 分钟后继续',
                'job' => $job,
                'waiting' => true,
                'wait_seconds' => $pauseMinutes * 60,
            ];
        }
        $job['batch_sent_in_cycle'] = 0;
    }

    $job['status'] = 'running';
    saveMailBroadcastJob($pdo, $job);

    return ['ok' => true, 'message' => '继续发送中', 'job' => $job];
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendMailBroadcastTest(PDO $pdo, string $email, ?array $config = null): array
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的测试邮箱'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $config = normalizeMailBroadcastConfig($config ?? getMailBroadcastConfig($pdo));
    if ($error = mailBroadcastConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $user = ['username' => '测试用户', 'email' => $email];
    $html = renderMailBroadcastEmailHtml($pdo, $config, $user);

    $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '测试发送失败'];
    }

    return ['ok' => true, 'message' => '测试邮件已发送至 ' . $email];
}

function mailBroadcastJobProgressLabel(?array $job): string
{
    if (!$job) {
        return '暂无任务';
    }

    $sent = (int)($job['sent_count'] ?? 0);
    $failed = (int)($job['failed_count'] ?? 0);
    $total = (int)($job['total_count'] ?? 0);
    $status = (string)($job['status'] ?? '');

    $labels = [
        'running' => '发送中',
        'paused' => '批次暂停',
        'completed' => '已完成',
        'cancelled' => '已取消',
    ];
    $statusLabel = $labels[$status] ?? $status;

    return $statusLabel . ' · 成功 ' . $sent . ' / 失败 ' . $failed . ' / 共 ' . $total;
}
