<?php
/**
 * 解析导航链接组件参数，避免连续 include 时变量互相污染
 */
function adminNavLinkResolve(array $scope, array $defaults): array
{
  $label = $defaults['label'] ?? '';
  if (array_key_exists('navLinkLabel', $scope)) {
    $label = $scope['navLinkLabel'];
  } elseif (array_key_exists('label', $scope)) {
    $label = $scope['label'];
  }

  return [
    'href' => $scope['href'] ?? ($defaults['href'] ?? '#'),
    'active' => !empty($scope['active']),
    'hoverClass' => $scope['hoverClass'] ?? ($defaults['hoverClass'] ?? 'hover:bg-gray-100'),
    'label' => $label,
  ];
}

function adminNavLinkCleanup(): void
{
    foreach (['href', 'active', 'hoverClass', 'label', 'navLinkLabel', 'paddingClass', 'linkExtraClass'] as $var) {
        if (array_key_exists($var, $GLOBALS)) {
            unset($GLOBALS[$var]);
        }
    }
}
