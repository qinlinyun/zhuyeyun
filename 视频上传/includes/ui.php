<?php

declare(strict_types=1);

/**
 * 远程上传后端页面 UI（Tailwind CSS 工具类）
 */

function uploadBackendAsset(string $path): string
{
    return 'assets/' . ltrim($path, '/');
}

/**
 * @param array{
 *   body_class?: string,
 *   minimal?: bool,
 *   viewport?: string
 * } $options
 */
function uploadBackendPageHead(string $title, array $options = []): void
{
    $minimal = !empty($options['minimal']);
    $defaultBody = $minimal
        ? 'min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 text-slate-900 antialiased'
        : 'min-h-screen bg-slate-100 text-slate-900 antialiased';
    $bodyClass = trim((string)($options['body_class'] ?? $defaultBody));
    $viewport = (string)($options['viewport'] ?? 'width=device-width, initial-scale=1.0');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="<?= htmlspecialchars($viewport, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(uploadBackendAsset('css/layout.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<?php
}

function uploadBackendPageFoot(): void
{
    echo "</body>\n</html>\n";
}

function uploadBackendTwNavLink(bool $active = false): string
{
    return $active
        ? 'rounded-lg px-3 py-1.5 text-sm font-semibold text-blue-600 bg-blue-50'
        : 'rounded-lg px-3 py-1.5 text-sm text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900';
}

/**
 * @param list<array{href: string, label: string, active?: bool}> $links
 */
function uploadBackendAdminNav(string $title, array $links = [], ?string $username = null): void
{
    ?>
<nav class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/90 shadow-sm backdrop-blur-md">
    <div class="mx-auto flex max-w-screen-xl items-center justify-between gap-3 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <a href="dashboard.php" class="shrink-0 text-sm font-semibold tracking-tight text-slate-900"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></a>
            <?php if ($links !== []): ?>
            <div class="hidden items-center gap-1 sm:flex">
                <?php foreach ($links as $link): ?>
                    <a href="<?= htmlspecialchars((string)$link['href'], ENT_QUOTES, 'UTF-8') ?>"
                       class="<?= uploadBackendTwNavLink(!empty($link['active'])) ?>"><?= htmlspecialchars((string)$link['label'], ENT_QUOTES, 'UTF-8') ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="flex shrink-0 items-center gap-2 text-sm">
            <?php if ($username !== null && $username !== ''): ?>
                <span class="hidden text-slate-500 sm:inline"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <a href="config_guide.php" class="<?= uploadBackendTwNavLink() ?>">配置引导</a>
            <a href="logout.php" class="<?= uploadBackendTwNavLink() ?>">退出</a>
        </div>
    </div>
</nav>
<?php
}

function uploadBackendTwAlertClass(string $type): string
{
    $map = [
        'success' => 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800',
        'error' => 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
        'info' => 'rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800',
        'warning' => 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900',
    ];

    return $map[$type] ?? $map['info'];
}

function uploadBackendAlert(string $type, string $message, bool $hidden = false): void
{
    if ($message === '' && !$hidden) {
        return;
    }
    $hiddenClass = $hidden ? ' hidden' : '';
    ?>
<div class="<?= uploadBackendTwAlertClass($type) ?><?= $hiddenClass ?> mb-4" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
<?php
}

function uploadBackendTwCard(string $extra = ''): string
{
    return 'rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/60 transition-shadow hover:shadow-md ' . trim($extra);
}

function uploadBackendBtnPrimary(string $extra = ''): string
{
    return 'inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500/30 disabled:cursor-not-allowed disabled:opacity-60 ' . trim($extra);
}

function uploadBackendBtnSecondary(string $extra = ''): string
{
    return 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200 disabled:cursor-not-allowed disabled:opacity-60 ' . trim($extra);
}

function uploadBackendBtnDanger(string $extra = ''): string
{
    return 'inline-flex items-center justify-center rounded-lg bg-red-600 px-3 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/30 disabled:cursor-not-allowed disabled:opacity-60 ' . trim($extra);
}

function uploadBackendInputClass(string $extra = ''): string
{
    return 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20 ' . trim($extra);
}

function uploadBackendFileInputClass(): string
{
    return 'w-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-700 transition hover:border-blue-300 hover:bg-blue-50';
}

function uploadBackendCodeClass(): string
{
    return 'rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700 break-all';
}

function uploadBackendProgressBlock(string $prefix): void
{
    $id = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    ?>
<div id="<?= $id ?>ProgressWrap" class="hidden rounded-lg border border-slate-200 bg-slate-50 p-4">
    <div class="mb-2 flex items-center justify-between text-xs text-slate-500">
        <span id="<?= $id ?>ProgressText">准备上传...</span>
        <span id="<?= $id ?>ProgressPercent" class="font-medium tabular-nums">0%</span>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-slate-200">
        <div id="<?= $id ?>ProgressBar" class="h-full w-0 rounded-full bg-blue-600 transition-all duration-200 ease-out"></div>
    </div>
</div>
<?php
}

function uploadBackendGuestNav(string $backHref, string $backLabel, string $title): void
{
    ?>
<nav class="border-b border-slate-200/80 bg-white/90 shadow-sm backdrop-blur-md">
    <div class="mx-auto flex max-w-screen-xl items-center justify-between px-4 py-3 text-sm">
        <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= uploadBackendTwNavLink() ?>"><?= htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8') ?></a>
        <span class="font-semibold text-slate-900"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
</nav>
<?php
}
