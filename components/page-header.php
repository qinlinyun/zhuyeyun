<?php
/**
 * @param string $title
 * @param string $desc
 * @param array  $actions [ ['label'=>'', 'icon'=>'', 'onclick'=>''] ]
 */
?>
<div class="mb-6">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
      <h1 class="text-xl font-semibold text-slate-800"><?=$title?></h1>
      <p class="text-sm text-slate-500 mt-1"><?=$desc?></p>
    </div>

    <?php if (!empty($actions)): ?>
    <div class="flex gap-2">
      <?php foreach ($actions as $btn): ?>
        <button
          onclick="<?=$btn['onclick']?>"
          class="inline-flex items-center gap-2 px-3 py-2 rounded-lg text-sm
                 border border-slate-200 bg-white hover:bg-slate-50 transition">
          <i class="<?=$btn['icon']?> text-slate-500"></i>
          <?=$btn['label']?>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
