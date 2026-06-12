<?php
/**
 * 统计卡片组件（安全兜底版）
 */
$label = $label ?? '';
$value = $value ?? '';
$icon  = $icon  ?? 'fa-solid fa-circle';
$color = $color ?? 'bg-slate-400';
?>

<div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
  <div class="flex items-center gap-3">
    <div class="w-10 h-10 rounded-lg flex items-center justify-center <?=$color?>">
      <i class="<?=$icon?> text-white"></i>
    </div>
    <div>
      <div class="text-sm text-slate-500"><?=$label?></div>
      <div class="text-lg font-semibold text-slate-800"><?=$value?></div>
    </div>
  </div>
</div>
