<?php

declare(strict_types=1);

final class FtpClient
{
    private $conn;

    private ?string $loginPwd = null;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function connect(): void
    {
        if ($this->conn !== null) {
            return;
        }

        $host = trim((string)($this->config['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('FTP 主机未配置');
        }

        $port = max(1, min(65535, (int)($this->config['port'] ?? 21)));
        $timeout = max(10, (int)($this->config['timeout'] ?? 90));
        $useSsl = !empty($this->config['ssl']);

        if ($useSsl && function_exists('ftp_ssl_connect')) {
            $conn = @ftp_ssl_connect($host, $port, $timeout);
        } else {
            $conn = @ftp_connect($host, $port, $timeout);
        }

        if ($conn === false) {
            throw new RuntimeException('无法连接 FTP 服务器：' . $host . ':' . $port);
        }

        $user = (string)($this->config['username'] ?? '');
        $pass = (string)($this->config['password'] ?? '');
        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            throw new RuntimeException('FTP 登录失败，请检查用户名和密码');
        }

        $this->applyPassiveMode($conn, !empty($this->config['passive']));

        $timeout = max(30, (int)($this->config['timeout'] ?? 90));
        if (defined('FTP_TIMEOUT_SEC')) {
            @ftp_set_option($conn, FTP_TIMEOUT_SEC, $timeout);
        }

        $this->conn = $conn;
        $this->loginPwd = (string)(@ftp_pwd($conn) ?: '/');
    }

    private function applyPassiveMode($conn, bool $passive): void
    {
        @ftp_pasv($conn, $passive);
    }

    public function disconnect(): void
    {
        if (is_resource($this->conn)) {
            @ftp_close($this->conn);
        }
        $this->conn = null;
        $this->loginPwd = null;
    }

    public function uploadFile(string $localPath, string $remoteRelativePath): array
    {
        if (!is_file($localPath)) {
            return ['ok' => false, 'error' => '本地临时文件不存在'];
        }

        $localSize = filesize($localPath) ?: 0;
        if ($localSize <= 0) {
            return ['ok' => false, 'error' => '本地视频文件为空'];
        }

        $this->connect();
        $relative = $this->storageKey($remoteRelativePath);
        if ($relative === '') {
            return ['ok' => false, 'error' => '远程路径无效'];
        }

        try {
            $this->navigateToParentOf($relative);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $fileName = basename($relative);
        $pwd = (string)(@ftp_pwd($this->conn) ?: '');
        $displayPath = $this->displayPath($relative);
        $failures = [];

        foreach ($this->putAttempts($fileName, $relative, $localPath) as $label => $attempt) {
            if ($attempt()) {
                $remoteSize = $this->probeRemoteSize($fileName, $relative);
                if ($remoteSize <= 0) {
                    $remoteSize = $localSize;
                }
                if ($remoteSize > 0) {
                    return [
                        'ok' => true,
                        'remote_path' => $relative,
                        'size_bytes' => $remoteSize,
                        'remote_pwd' => (string)(@ftp_pwd($this->conn) ?: $pwd),
                    ];
                }
                $failures[] = $label . '：上传命令成功但未检测到文件';
                continue;
            }
            $failures[] = $label;
        }

        return [
            'ok' => false,
            'error' => 'FTP 上传失败，目标：' . $displayPath
                . '（当前 FTP 目录：' . ($pwd !== '' ? $pwd : '未知') . '）。'
                . ' 请确认该目录可写，并尝试切换「被动模式」勾选状态后重试。'
                . ($failures !== [] ? ' 尝试：' . implode('；', $failures) : ''),
        ];
    }

    private function displayPath(string $relative): string
    {
        $base = $this->normalizeDir((string)($this->config['base_path'] ?? ''));

        return $base !== '' ? $base . '/' . $relative : $relative;
    }

    private function navigateToParentOf(string $relative): void
    {
        $parent = dirname($relative);
        if ($parent === '.' || $parent === '') {
            $entered = $this->changeToBasePath(true);
            if (!$entered['ok']) {
                throw new RuntimeException((string)($entered['error'] ?? '无法进入远程根目录'));
            }

            return;
        }

        $this->ensureRemoteDir($parent);
    }

    /** @return array<string, callable(): bool> */
    private function putAttempts(string $fileName, string $relative, string $localPath): array
    {
        $passivePreferred = !empty($this->config['passive']);

        return [
            '流式上传(当前目录)' => fn () => $this->fputLocal($fileName, $localPath),
            '直传(当前目录)' => fn () => @ftp_put($this->conn, $fileName, $localPath, FTP_BINARY),
            '流式上传(切换被动模式)' => function () use ($fileName, $localPath, $passivePreferred) {
                $this->applyPassiveMode($this->conn, !$passivePreferred);
                $ok = $this->fputLocal($fileName, $localPath);
                $this->applyPassiveMode($this->conn, $passivePreferred);

                return $ok;
            },
            '完整相对路径' => function () use ($relative, $localPath) {
                $this->restoreLoginDirectory();
                $entered = $this->changeToBasePath(true);
                if (empty($entered['ok'])) {
                    return false;
                }

                return $this->fputLocal($relative, $localPath) || @ftp_put($this->conn, $relative, $localPath, FTP_BINARY);
            },
        ];
    }

    private function fputLocal(string $remoteName, string $localPath): bool
    {
        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return false;
        }

        $ok = @ftp_fput($this->conn, $remoteName, $handle, FTP_BINARY);
        fclose($handle);

        return $ok;
    }

    private function probeRemoteSize(string $fileName, string $relative): int
    {
        foreach ([$fileName, $relative, './' . $fileName] as $candidate) {
            $size = @ftp_size($this->conn, $candidate);
            if (is_int($size) && $size > 0) {
                return $size;
            }
        }

        $list = @ftp_nlist($this->conn, '.');
        if (is_array($list)) {
            $baseName = basename($fileName);
            foreach ($list as $item) {
                if (basename((string)$item) === $baseName) {
                    $size = @ftp_size($this->conn, (string)$item);
                    if (is_int($size) && $size > 0) {
                        return $size;
                    }

                    return 1;
                }
            }
        }

        return 0;
    }

    public function deleteFile(string $remoteRelativePath): bool
    {
        $key = $this->storageKey($remoteRelativePath);
        if ($key === '') {
            return false;
        }

        try {
            $this->connect();
            $parent = dirname($key);
            $fileName = basename($key);
            if ($parent !== '.' && $parent !== '') {
                $this->ensureRemoteDir($parent);
            } else {
                $entered = $this->changeToBasePath(false);
                if (!$entered['ok']) {
                    return false;
                }
            }

            return @ftp_delete($this->conn, $fileName);
        } catch (Throwable) {
            return false;
        }
    }

    public function testConnection(): array
    {
        try {
            $this->connect();
            $loginPwd = (string)(@ftp_pwd($this->conn) ?: '');
            $base = $this->normalizeDir((string)($this->config['base_path'] ?? ''));

            if ($base !== '') {
                $entered = $this->changeToBasePath(false);
                $autoCreated = false;
                if (!$entered['ok']) {
                    $entered = $this->changeToBasePath(true);
                    $autoCreated = !empty($entered['ok']);
                }
                if (!$entered['ok']) {
                    return [
                        'ok' => false,
                        'error' => $entered['error'],
                        'login_pwd' => $loginPwd,
                        'base_path' => $base,
                    ];
                }
                if ($autoCreated) {
                    return [
                        'ok' => true,
                        'message' => 'FTP 连接成功，远程目录不存在已自动创建',
                        'login_pwd' => $loginPwd,
                        'pwd' => (string)(@ftp_pwd($this->conn) ?: ''),
                        'base_path' => $base,
                    ];
                }
            }

            $finalPwd = (string)(@ftp_pwd($this->conn) ?: '');

            return [
                'ok' => true,
                'message' => 'FTP 连接成功' . ($base !== '' ? '，已进入远程目录' : ''),
                'login_pwd' => $loginPwd,
                'pwd' => $finalPwd,
                'base_path' => $base,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $this->disconnect();
        }
    }

    public function buildUserRemotePath(int $userId, string $storedName, ?string $videoFolder = null): string
    {
        $subdir = trim((string)($this->config['user_subdir'] ?? 'users'), '/');
        if ($subdir === '') {
            $subdir = 'users';
        }
        $folder = $videoFolder ?? UploadSupport::generateVideoFolderId();
        $storedName = ltrim(str_replace('\\', '/', $storedName), '/');

        return $subdir . '/' . max(1, $userId) . '/' . $folder . '/' . $storedName;
    }

    public function storageKey(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return '';
        }

        return $relativePath;
    }

    private function absoluteRemotePath(string $relativePath): string
    {
        $base = $this->normalizeDir((string)($this->config['base_path'] ?? ''));
        $relative = $this->storageKey($relativePath);
        if ($relative === '') {
            throw new InvalidArgumentException('远程路径无效');
        }

        return $base !== '' ? $base . '/' . $relative : $relative;
    }

    private function normalizeDir(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        return trim($path, '/');
    }

    /** @return array{ok: bool, error?: string} */
    private function changeToBasePath(bool $createIfMissing): array
    {
        $base = $this->normalizeDir((string)($this->config['base_path'] ?? ''));
        if ($base === '') {
            return ['ok' => true];
        }

        $loginPwd = $this->loginPwd ?? (string)(@ftp_pwd($this->conn) ?: '/');
        $segments = array_values(array_filter(explode('/', $base), static fn (string $s) => $s !== ''));

        if ($this->restoreLoginDirectory() && $this->chdirSegments($segments, $createIfMissing)) {
            return ['ok' => true];
        }

        @ftp_chdir($this->conn, '/');
        if ($this->chdirSegments($segments, $createIfMissing)) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'error' => '无法进入远程目录「' . $base . '」。'
                . ' 登录后位于：' . ($loginPwd !== '' ? $loginPwd : '（未知）')
                . '。请填写相对该目录的路径（如 mp4），勿填服务器上无权限的绝对路径；'
                . ' 可用 FileZilla 登录后查看实际路径。'
                . ($createIfMissing ? '' : ' 若目录不存在，可先创建或勾选测试时自动创建。'),
        ];
    }

    private function restoreLoginDirectory(): bool
    {
        $pwd = $this->loginPwd;
        if ($pwd === null || $pwd === '') {
            return true;
        }

        return @ftp_chdir($this->conn, $pwd);
    }

    /** @param list<string> $segments */
    private function chdirSegments(array $segments, bool $createIfMissing): bool
    {
        if ($segments === []) {
            return true;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if (@ftp_chdir($this->conn, $segment)) {
                continue;
            }
            if (!$createIfMissing) {
                return false;
            }
            if (!@ftp_mkdir($this->conn, $segment) || !@ftp_chdir($this->conn, $segment)) {
                return false;
            }
        }

        return true;
    }

    private function ensureRemoteDir(string $relativeDir): void
    {
        $relativeDir = $this->normalizeDir($relativeDir);
        $entered = $this->changeToBasePath(true);
        if (!$entered['ok']) {
            throw new RuntimeException((string)($entered['error'] ?? '无法进入 FTP 远程根目录'));
        }

        if ($relativeDir === '') {
            return;
        }

        foreach (explode('/', $relativeDir) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if (@ftp_chdir($this->conn, $segment)) {
                continue;
            }
            if (!@ftp_mkdir($this->conn, $segment) || !@ftp_chdir($this->conn, $segment)) {
                throw new RuntimeException('无法创建 FTP 目录：' . $segment);
            }
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
