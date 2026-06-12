<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<title>观看记录</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include __DIR__ . '/components/theme-head.php'; ?>
<?php include __DIR__ . '/components/theme-dynamic.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-slate-100 text-slate-800">

<!-- ✅ 顶部导航 -->
<div class="bg-white border-b sticky top-0 z-40">
  <div class="max-w-6xl mx-auto px-4 h-12 flex items-center justify-between">
    
    <a href="/"
       onclick="stopSSE()"
       class="flex items-center gap-2 text-sm text-slate-700 hover:text-red-500 transition">
      <i class="fa-solid fa-house"></i>
      <span>返回主页</span>
    </a>
    <?php include __DIR__ . '/components/theme-toggle.php'; ?>
  </div>
</div>

<div class="max-w-6xl mx-auto px-4 py-6">

<?php
$title = '我的观看记录';
$desc  = '自动保存的观看进度，可随时继续播放';
$actions = [
  ['label'=>'清空记录','icon'=>'fa-solid fa-trash','onclick'=>'clearAll()']
];
include 'components/page-header.php';
?>

<div id="emptyState" class="hidden">
  <?php include 'components/empty-state.php'; ?>
</div>

<div id="list" class="grid grid-cols-1 lg:grid-cols-2 gap-4"></div>

</div>

<script>
let es = null;
let refreshing = false;
let listSince = '';

function cardKey(item) {
  return `${item.video_id}_${item.episode_id}`;
}

function renderCard(item) {
  const p = Number(item.progress_seconds || 0);
  const du = Number(item.duration_seconds || 0);
  const pct = du > 0 ? Math.min(100, Math.round(p / du * 100)) : 0;
  const playHref = `play.php?id=${item.video_id}&episode=${item.episode_id}&t=${p}`;
  const cover = String(item.cover_url || item.cover || '').trim();
  const coverHtml = cover
    ? `<img src="${escapeHtml(cover)}" alt="${escapeHtml(item.title)}" loading="lazy" decoding="async" referrerpolicy="no-referrer"\n+            onerror="this.onerror=null;this.style.display='none';this.parentElement.classList.add('is-fallback')"\n+            class="h-full w-full object-cover">`
    : '';

  const updated = String(item.updated_at || '');
  const updatedText = updated ? `上次观看：${escapeHtml(updated)}` : '';

  const progressText = du > 0
    ? `${fmtTime(p)} / ${fmtTime(du)}`
    : `${fmtTime(p)}`;

  return `
    <a class="relative block rounded-xl border border-slate-200 bg-white p-3 shadow-sm transition hover:shadow-md dark:border-slate-700 dark:bg-slate-900/80 cursor-pointer"
       data-key="${cardKey(item)}"
       href="${playHref}"
       onclick="stopSSE()">
      <div class="flex gap-3">
        <div class="coverBox h-24 w-24 shrink-0 overflow-hidden rounded bg-slate-200 dark:bg-slate-800">
          ${coverHtml}
          <div class="fallback hidden h-full w-full items-center justify-center text-xs text-slate-500 dark:text-slate-400">暂无封面</div>
        </div>
        <div class="min-w-0 flex-1 pr-10">
          <div class="truncate rounded-md bg-lime-500/10 px-2 py-1 text-sm font-semibold text-slate-900 dark:text-slate-100">
            ${escapeHtml(item.title)}
          </div>
          <div class="mt-2 truncate rounded-md bg-sky-500/10 px-2 py-1 text-xs text-slate-700 dark:text-slate-200">
            ${escapeHtml(item.episode_name)}
          </div>

          <div class="mt-2 flex items-center justify-between gap-2 px-2">
            <div class="text-xs text-slate-600 dark:text-slate-300">${progressText}</div>
            <div class="text-xs font-semibold text-slate-900 dark:text-white">${pct}%</div>
          </div>
          <div class="mt-2 px-2">
            <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
              <div class="h-2 rounded-full bg-red-500 transition-all" style="width:${pct}%"></div>
            </div>
          </div>
          ${updatedText ? `<div class="mt-2 px-2 text-[11px] text-slate-500 dark:text-slate-400">${updatedText}</div>` : ``}
        </div>
      </div>
      <button
        type="button"
        title="删除记录"
        onclick="event.preventDefault();event.stopPropagation();delOne(${item.video_id},${item.episode_id})"
        class="absolute right-3 top-3 inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white hover:bg-slate-50 dark:border-white/20 dark:bg-slate-800/70 dark:hover:bg-slate-700">
        <svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" aria-hidden="true">
          <path d="M607.897867 768.043004c-17.717453 0-31.994625-14.277171-31.994625-31.994625L575.903242 383.935495c0-17.717453 14.277171-31.994625 31.994625-31.994625s31.994625 14.277171 31.994625 31.994625l0 351.94087C639.892491 753.593818 625.61532 768.043004 607.897867 768.043004z" fill="#1296db"></path>
          <path d="M415.930119 768.043004c-17.717453 0-31.994625-14.277171-31.994625-31.994625L383.935495 383.935495c0-17.717453 14.277171-31.994625 31.994625-31.994625 17.717453 0 31.994625 14.277171 31.994625 31.994625l0 351.94087C447.924744 753.593818 433.647573 768.043004 415.930119 768.043004z" fill="#1296db"></path>
          <path d="M928.016126 223.962372l-159.973123 0L768.043004 159.973123c0-52.980346-42.659499-95.983874-95.295817-95.983874L351.94087 63.989249c-52.980346 0-95.983874 43.003528-95.983874 95.983874l0 63.989249-159.973123 0c-17.717453 0-31.994625 14.277171-31.994625 31.994625s14.277171 31.994625 31.994625 31.994625l832.032253 0c17.717453 0 31.994625-14.277171 31.994625-31.994625S945.73358 223.962372 928.016126 223.962372zM319.946246 159.973123c0-17.545439 14.449185-31.994625 31.994625-31.994625l320.806316 0c17.545439 0 31.306568 14.105157 31.306568 31.994625l0 63.989249L319.946246 223.962372 319.946246 159.973123 319.946246 159.973123z" fill="#1296db"></path>
          <path d="M736.048379 960.010751 288.123635 960.010751c-52.980346 0-95.983874-43.003528-95.983874-95.983874L192.139761 383.591466c0-17.717453 14.277171-31.994625 31.994625-31.994625s31.994625 14.277171 31.994625 31.994625l0 480.435411c0 17.717453 14.449185 31.994625 31.994625 31.994625l448.096758 0c17.717453 0 31.994625-14.277171 31.994625-31.994625L768.215018 384.795565c0-17.717453 14.277171-31.994625 31.994625-31.994625s31.994625 14.277171 31.994625 31.994625l0 479.231312C832.032253 916.835209 789.028725 960.010751 736.048379 960.010751z" fill="#1296db"></path>
        </svg>
      </button>
    </a>`;
}

function fmtTime(sec) {
  sec = Math.max(0, Math.floor(Number(sec || 0)));
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

function upsertCards(items) {
  const box = document.getElementById('list');
  const empty = document.getElementById('emptyState');
  if (!items.length) return;
  empty.classList.add('hidden');
  items.forEach(item => {
    const html = renderCard(item);
    const key = cardKey(item);
    const existing = box.querySelector(`[data-key="${key}"]`);
    if (existing) {
      existing.outerHTML = html;
    } else {
      box.insertAdjacentHTML('afterbegin', html);
    }
    if (item.updated_at && (!listSince || item.updated_at > listSince)) {
      listSince = item.updated_at;
    }
  });
  ensureCoverFallbacks();
}

function startSSE() {
  stopSSE();
  es = new EventSource('api/progress_sse.php');

  es.addEventListener('update', (ev) => {
    if (refreshing) return;
    refreshing = true;
    const run = async () => {
      try {
        if (ev.data) {
          const row = JSON.parse(ev.data);
          if (row.video_id && row.episode_id) {
            const r = await fetch('api/my_progress_list.php?since=' + encodeURIComponent(listSince || ''), { credentials: 'same-origin' });
            const d = await r.json();
            if (d.list && d.list.length) upsertCards(d.list);
            else await loadList(true);
            refreshing = false;
            return;
          }
        }
      } catch (e) {}
      await loadList(true);
      refreshing = false;
    };
    setTimeout(run, 300);
  });

  es.onerror = () => {
    stopSSE();
    if (!document.hidden) setTimeout(startSSE, 2000);
  };
}

function stopSSE() {
  try { es && es.close(); } catch (e) {}
  es = null;
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) stopSSE();
  else { loadList(true); startSSE(); }
});

async function loadList(quiet = false) {
  const url = listSince
    ? `api/my_progress_list.php?since=${encodeURIComponent(listSince)}`
    : 'api/my_progress_list.php';
  const r = await fetch(url, { credentials: 'same-origin' });
  const d = await r.json();

  const box = document.getElementById('list');
  const empty = document.getElementById('emptyState');

  if (quiet && d.list && d.list.length) {
    upsertCards(d.list);
    ensureCoverFallbacks();
    return;
  }

  if (!d.list || !d.list.length) {
    if (!listSince) {
      box.innerHTML = '';
      empty.classList.remove('hidden');
    }
    return;
  }

  empty.classList.add('hidden');
  box.innerHTML = d.list.map(renderCard).join('');
  ensureCoverFallbacks();
  d.list.forEach(item => {
    if (item.updated_at && (!listSince || item.updated_at > listSince)) {
      listSince = item.updated_at;
    }
  });
}

/* ================== 操作 ================== */
function delOne(videoId, episodeId){
  Swal.fire({
    title:'确认删除？',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(async r=>{
    if(!r.isConfirmed) return;
    await fetch('api/delete_progress.php',{
      method:'POST',
      credentials:'same-origin',
      body:new URLSearchParams({video_id:videoId,episode_id:episodeId})
    });
    const el = document.querySelector(`[data-key="${videoId}_${episodeId}"]`);
    if (el) el.remove();
  });
}

function clearAll(){
  Swal.fire({
    title:'清空所有记录？',
    icon:'warning',
    showCancelButton:true,
    confirmButtonColor:'#ef4444'
  }).then(async r=>{
    if(!r.isConfirmed) return;
    await fetch('api/clear_progress.php',{method:'POST',credentials:'same-origin'});
    listSince = '';
    await loadList();
  });
}

function escapeHtml(str){
  return String(str)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function ensureCoverFallbacks() {
  document.querySelectorAll('.coverBox').forEach(box => {
    const img = box.querySelector('img');
    const fallback = box.querySelector('.fallback');
    if (!fallback) return;
    if (!img || img.style.display === 'none') {
      fallback.classList.remove('hidden');
      fallback.classList.add('flex');
    } else {
      fallback.classList.add('hidden');
      fallback.classList.remove('flex');
    }
  });
}

/* 启动 */
loadList();
startSSE();
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>

</body>
</html>
