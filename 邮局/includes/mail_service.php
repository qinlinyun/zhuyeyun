<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smtp_mailer.php';

/**
 * @return array{ok:bool,message?:string}
 */
function mailServerSend(string $to, string $subject, string $body, bool $isHtml = false): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '收件人邮箱无效'];
    }

    $config = mailServerLoadConfig();
    if (!mailServerSmtpReady($config)) {
        return ['ok' => false, 'message' => '邮局 SMTP 未配置完整'];
    }

    try {
        $mailer = new MailServerSmtpMailer(mailServerSmtpConfigForMailer($config));
        $mailer->send($to, $subject, $body, $isHtml);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
