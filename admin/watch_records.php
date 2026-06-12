<?php
require_once '../includes/auth.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    exit('403 Forbidden');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>所有用户观看记录</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>


<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css" rel="stylesheet">
</head>

<body class="bg-slate-100 text-slate-800">

<?php $adminNavActive = ''; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<div class="max-w-7xl mx-auto px-4 py-6">

<?php
$title = '所有用户观看记录';
$desc  = '实时同步的全站观看行为（管理员）';
$actions = [
  ['label'=>'刷新','icon'=>'fa-solid fa-rotate','onclick'=>'loadList()']
];
include '../components/page-header.php';
?>

<div id="list" class="space-y-3"></div>

</div>

<script>
let es = null;
let refreshing = false;
let listSince = '';

function recordKey(item) {
  return `${item.user_id}_${item.video_id}_${item.episode_id}`;
}

function renderRecord(item) {
  const pct = item.duration_seconds > 0
    ? Math.min(100, Math.round(item.progress_seconds / item.duration_seconds * 100))
    : 0;
  const username = (item.username || '').replace(/</g, '&lt;');
  const title = (item.title || '').replace(/</g, '&lt;');
  const ep = (item.episode_name || '').replace(/</g, '&lt;');
  return `
    <div class="bg-white rounded-xl p-4 shadow-sm border" data-key="${recordKey(item)}">
      <div class="flex justify-between items-start gap-4">
        <div>
          <div class="font-medium">
            <i class="fa-solid fa-user mr-1 text-slate-400"></i>
            ${username}
          </div>
          <div class="text-sm text-slate-500">${title} · ${ep}</div>
        </div>
        <a onclick="stopSSE()"
           href="../play.php?id=${item.video_id}&episode=${item.episode_id}&t=${item.progress_seconds}"
           class="text-sm text-red-500 hover:text-red-600">▶ 查看</a>
      </div>
      <div class="mt-3 h-2 bg-slate-200 rounded">
        <div class="h-2 bg-red-500" style="width:${pct}%"></div>
      </div>
      <div class="mt-1 text-xs text-slate-500">${pct}% · ${item.updated_at || ''}</div>
    </div>`;
}

function upsertRecords(items) {
  const box = document.getElementById('list');
  if (!items.length) return;
  items.forEach(item => {
    const html = renderRecord(item);
    const key = recordKey(item);
    const existing = box.querySelector(`[data-key="${key}"]`);
    if (existing) existing.outerHTML = html;
    else box.insertAdjacentHTML('afterbegin', html);
    if (item.updated_at && (!listSince || item.updated_at > listSince)) {
      listSince = item.updated_at;
    }
  });
}

async function loadList(quiet = false) {
  const url = listSince
    ? `../api/admin_watch_records.php?since=${encodeURIComponent(listSince)}`
    : '../api/admin_watch_records.php';
  const r = await fetch(url, { credentials: 'same-origin' });
  const d = await r.json();
  const box = document.getElementById('list');

  if (quiet && d.list && d.list.length) {
    upsertRecords(d.list);
    return;
  }

  if (!d.list || !d.list.length) {
    if (!listSince) {
      box.innerHTML = `
        <div class="text-center py-20 text-slate-400">
          <i class="fa-regular fa-folder-open text-4xl mb-3"></i>
          <div>暂无数据</div>
        </div>`;
    }
    return;
  }

  box.innerHTML = d.list.map(renderRecord).join('');
  d.list.forEach(item => {
    if (item.updated_at && (!listSince || item.updated_at > listSince)) {
      listSince = item.updated_at;
    }
  });
}

function startSSE() {
  stopSSE();
  es = new EventSource('../api/progress_sse.php?mode=admin');

  es.addEventListener('update', (ev) => {
    if (refreshing) return;
    refreshing = true;
    const run = async () => {
      try {
        if (ev.data) {
          const row = JSON.parse(ev.data);
          if (row.user_id && row.video_id) {
            const r = await fetch(`../api/admin_watch_records.php?since=${encodeURIComponent(listSince || '')}`, { credentials: 'same-origin' });
            const d = await r.json();
            if (d.list && d.list.length) upsertRecords(d.list);
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

loadList();
startSSE();
</script>

</body>
</html>
