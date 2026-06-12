<?php
$pct = $item['duration_seconds'] > 0
  ? min(100, round($item['progress_seconds'] / $item['duration_seconds'] * 100))
  : 0;
?>
<div class="bg-white rounded-xl p-4 shadow-sm border border-slate-100
            hover:shadow-md transition">
  <div class="flex justify-between gap-3">
    <div class="min-w-0">
      <div class="font-medium text-slate-800 truncate"><?=$item['title']?></div>
      <div class="text-xs text-slate-500 mt-1"><?=$item['episode_name']?></div>
    </div>
    <span class="text-xs text-slate-400"><?=$pct?>%</span>
  </div>

  <div class="mt-3 h-2 bg-slate-200 rounded overflow-hidden">
    <div class="h-2 bg-red-500 transition-all" style="width:<?=$pct?>%"></div>
  </div>

  <div class="mt-4 flex gap-2">
    <a href="play.php?id=<?=$item['video_id']?>&episode=<?=$item['episode_id']?>&t=<?=$item['progress_seconds']?>"
       class="flex-1 text-center text-sm rounded-lg bg-red-500 text-white py-2 hover:bg-red-600">
      <i class="fa-solid fa-play"></i> 继续观看
    </a>
    <button
      onclick="delOne(<?=$item['video_id']?>,<?=$item['episode_id']?>)"
      class="px-3 rounded-lg border hover:bg-slate-50">
      <i class="fa-solid fa-trash"></i>
    </button>
  </div>
</div>
