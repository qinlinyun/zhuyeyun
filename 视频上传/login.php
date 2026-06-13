<?php
require_once __DIR__ . '/common.php';

$config = uploadBackendConfig();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mainSite = trim((string)$config['MAIN_SITE_URL']);
    $token = (string)$config['API_TOKEN'];
    if (!uploadBackendVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '表单已过期，请刷新后重试';
    } elseif ($mainSite === '' || $token === '') {
        $error = '请先在 config.php 中配置 MAIN_SITE_URL 和 API_TOKEN';
    } else {
        $result = uploadBackendPostJson(uploadBackendUrl('api/upload_admin_auth.php'), [
            'api_token' => $token,
            'username' => trim((string)($_POST['username'] ?? '')),
            'password' => (string)($_POST['password'] ?? ''),
        ]);
        if (!empty($result['ok'])) {
            session_regenerate_id(true);
            $_SESSION['upload_backend_admin'] = $result['admin'];
            header('Location: dashboard.php');
            exit;
        }
        $error = (string)($result['error'] ?? '登录失败');
    }
}

uploadBackendPageHead('远程上传后端登录', [
    'body_class' => 'flex min-h-screen items-center justify-center bg-gradient-to-br from-blue-50 via-slate-50 to-slate-100 px-4 text-slate-900 antialiased',
]);
?>
<div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl ring-1 ring-slate-200/60">
    <div class="mb-6 text-center">
        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-600/30">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
        </div>
        <h1 class="text-xl font-bold text-slate-900">远程上传后端</h1>
        <p class="mt-1 text-sm text-slate-500">使用主站管理员账号登录</p>
    </div>
    <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(uploadBackendCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($error): uploadBackendAlert('error', $error); endif; ?>
        <div>
            <label for="username" class="mb-1 block text-sm font-medium text-slate-700">管理员用户名</label>
            <input id="username" name="username" class="<?= uploadBackendInputClass() ?>" autocomplete="username" required>
        </div>
        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">管理员密码</label>
            <input id="password" name="password" type="password" class="<?= uploadBackendInputClass() ?>" autocomplete="current-password" required>
        </div>
        <button type="submit" class="<?= uploadBackendBtnPrimary('w-full py-2.5') ?>">登录</button>
        <a href="config_guide.php" class="block text-center text-sm text-blue-600 transition hover:text-blue-800">配置引导 / 主站 API 检测</a>
    </form>
</div>
<?php uploadBackendPageFoot(); ?>
