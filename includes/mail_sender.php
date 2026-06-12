<?php

require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_api_client.php';

/**
 * 使用邮局 SMTP 配置发送邮件（供注册验证码、通知等功能调用）
 *
 * @return array{ok:bool,message?:string}
 */
function sendSiteMail(PDO $pdo, string $to, string $subject, string $body, bool $isHtml = false): array
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '收件人邮箱无效'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '邮局未配置或未启用，请先在「邮局配置」中完成设置'];
    }

    $cfg = getMailSmtpConfig($pdo);

    if (isMailApiMode($cfg)) {
        return sendSiteMailViaApi($cfg, $to, $subject, $body, $isHtml);
    }

    try {
        $mailer = new SiteSmtpMailer($cfg);
        $mailer->send($to, $subject, $body, $isHtml);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

final class SiteSmtpMailer
{
    private array $cfg;
    /** @var resource|null */
    private $socket;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function send(string $to, string $subject, string $body, bool $isHtml = false): void
    {
        $this->connect();
        $this->expect([220]);
        $this->cmd('EHLO ' . $this->hostname(), [250]);

        if ($this->cfg['encryption'] === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->socket, true, self::tlsCryptoMethods())) {
                throw new RuntimeException('STARTTLS 握手失败');
            }
            $this->cmd('EHLO ' . $this->hostname(), [250]);
        }

        $this->authenticate();

        $from = $this->cfg['from_email'];
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);

        $message = $this->buildMessage($to, $subject, $body, $isHtml);
        fwrite($this->socket, $message);
        $this->expect([250]);
        $this->cmd('QUIT', [221]);
        $this->close();
    }

    private function connect(): void
    {
        $host = trim((string)$this->cfg['host']);
        $port = (int)$this->cfg['port'];
        $connectHost = self::resolveConnectHost($host);
        $scheme = $this->cfg['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $remote = $scheme . '://' . $connectHost . ':' . $port;
        $context = self::streamContext($host);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            $hint = self::connectionHint($host, $port, (string)$this->cfg['encryption']);
            $detail = $errstr !== '' ? $errstr : (string)$errno;
            throw new RuntimeException('无法连接 SMTP 服务器：' . $detail . $hint);
        }

        stream_set_timeout($socket, 30);
        $this->socket = $socket;
    }

    private static function resolveConnectHost(string $host): string
    {
        if ($host === '') {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // 优先 IPv4，避免域名仅有 AAAA 记录在部分云环境长时间超时
        $ipv4 = gethostbyname($host);
        if ($ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ipv4;
        }

        return $host;
    }

    /** @return resource */
    private static function streamContext(string $peerName)
    {
        $ssl = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'peer_name' => $peerName,
        ];

        return stream_context_create(['ssl' => $ssl]);
    }

    private static function tlsCryptoMethods(): int
    {
        $methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        return $methods;
    }

    private static function connectionHint(string $host, int $port, string $encryption): string
    {
        $tips = [];
        if ($encryption === 'ssl' && $port === 587) {
            $tips[] = '587 端口通常应选 TLS 而非 SSL';
        }
        if ($encryption === 'tls' && $port === 465) {
            $tips[] = '465 端口通常应选 SSL 而非 TLS';
        }
        if ($host === '127.0.0.1' || strcasecmp($host, 'localhost') === 0) {
            $tips[] = '网站与邮局不在同一台机器时请填邮局公网 IP 或域名';
        }

        return $tips === [] ? '' : '（' . implode('；', $tips) . '）';
    }

    private function authenticate(): void
    {
        $username = (string)$this->cfg['username'];
        $password = (string)$this->cfg['password'];

        try {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($username), [334]);
            $this->cmd(base64_encode($password), [235]);
            return;
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            if (strpos($message, '504') === false && strpos($message, '502') === false && strpos($message, '535') === false) {
                throw $e;
            }
        }

        $plain = base64_encode("\0" . $username . "\0" . $password);
        $this->cmd('AUTH PLAIN ' . $plain, [235]);
    }

    private function hostname(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', $host);

        return $host !== '' ? $host : 'localhost';
    }

    private function cmd(string $command, array $okCodes): void
    {
        fwrite($this->socket, $command . "\r\n");
        $this->expect($okCodes);
    }

    /** @param int[] $okCodes */
    private function expect(array $okCodes): void
    {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('SMTP 服务器无响应');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException(trim($response));
        }
    }

    private function buildMessage(string $to, string $subject, string $body, bool $isHtml): string
    {
        $fromEmail = $this->cfg['from_email'];
        $fromName = $this->cfg['from_name'];
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $contentType = $isHtml ? 'text/html' : 'text/plain';

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encodedName . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $payload = implode("\r\n", $headers)
            . "\r\n\r\n"
            . chunk_split(base64_encode($body))
            . "\r\n.\r\n";

        return $payload;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

/**
 * 发送测试邮件（邮局配置页使用）
 *
 * @return array{ok:bool,message?:string}
 */
function sendSiteMailTest(PDO $pdo, string $to): array
{
    $cfg = getMailSmtpConfig($pdo);
    if (isMailApiMode($cfg)) {
        return sendSiteMailTestViaApi($cfg, $to);
    }

    $subject = '邮局 SMTP 测试邮件';
    $body = "这是一封测试邮件。\n\n如果您收到此邮件，说明 SMTP 配置正确。\n\n发送时间：" . date('Y-m-d H:i:s');

    return sendSiteMail($pdo, $to, $subject, $body, false);
}
