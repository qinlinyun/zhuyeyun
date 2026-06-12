<?php
/**
 * 用户头像：已上传显示图片，否则显示系统默认 SVG（与导航栏原图标一致）
 *
 * @var array|null $user 含 avatar 字段的用户数组
 * @var string $imgClass 自定义头像 img 的 class
 * @var string $svgClass 默认 SVG 的 class
 */
$user = $user ?? null;
$imgClass = $imgClass ?? 'h-8 w-8 rounded-full object-cover border border-gray-200 bg-gray-50';
$svgClass = $svgClass ?? 'h-8 w-8 shrink-0';
require_once __DIR__ . '/../includes/user_profile.php';

if (!empty($user['avatar'])): ?>
<img src="<?= htmlspecialchars((string)$user['avatar']) ?>" alt="" class="<?= htmlspecialchars($imgClass) ?>">
<?php else: ?>
<svg class="<?= htmlspecialchars($svgClass) ?>" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M512 102.4c87.04 0 153.6 66.56 153.6 153.6s-66.56 153.6-153.6 153.6-153.6-66.56-153.6-153.6 66.56-153.6 153.6-153.6m0-102.4C368.64 0 256 112.64 256 256s112.64 256 256 256 256-112.64 256-256-112.64-256-256-256zM819.2 716.8c56.32 0 102.4 46.08 102.4 102.4v102.4H102.4v-102.4c0-56.32 46.08-102.4 102.4-102.4h614.4m0-102.4H204.8c-112.64 0-204.8 92.16-204.8 204.8v102.4c0 56.32 46.08 102.4 102.4 102.4h819.2c56.32 0 102.4-46.08 102.4-102.4v-102.4c0-112.64-92.16-204.8-204.8-204.8z" fill="#1CD8D2"/></svg>
<?php endif; ?>
