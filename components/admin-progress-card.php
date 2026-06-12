<?php
$pct = $item['duration_seconds'] > 0
  ? min(100, round($item['progress_seconds'] / $item['duration_seconds'] * 100))
  : 0;
?>
<div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100">
  <div class="flex justify-between items-start gap-3">
    <div>
      <div class="font-medium"><?=$item['username']?></div>
      <div class="text-sm text-slate-500">
        <?=$item['title']?> · <?=$item['episode_name']?>
      </div>
    </div>
    <a href="../play.php?id=<?=$item['video_id']?>&episode=<?=$item['episode_id']?>&t=<?=$item['progress_seconds']?>"
       class="text-red-500 hover:text-red-600">
      <i class="fa-solid fa-play"></i>
    </a>
  </div>

  <div class="mt-3 h-2 bg-slate-200 rounded overflow-hidden">
    <div class="h-2 bg-red-500" style="width:<?=$pct?>%"></div>
  </div>

  <div class="mt-1 text-xs text-slate-500 flex justify-between">
    <span><?=$pct?>%</span>
    <span><?=$item['updated_at']?></span>
  </div>
</div>
