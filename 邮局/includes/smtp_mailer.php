<?php

final class MailServerSmtpMailer
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
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $ipv4 = gethostbyname($host);
        if ($ipv4 !== $host && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ipv4;
        }

        return $host;
    }

    /** @return resource */
    private static function streamContext(string $peerName)
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'peer_name' => $peerName,
            ],
        ]);
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

        return implode("\r\n", $headers)
            . "\r\n\r\n"
            . chunk_split(base64_encode($body))
            . "\r\n.\r\n";
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
