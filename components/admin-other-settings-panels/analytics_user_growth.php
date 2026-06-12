<?php
/** @var string $analyticsDescription */
?>
<div class="px-4 py-4">
    <p class="text-sm text-gray-500 mb-4">
        <?= htmlspecialchars($analyticsDescription, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <div class="mb-4 flex flex-wrap gap-2" id="user-growth-range">
        <?php
        $ranges = [
            '24h' => '24小时',
            '7d' => '7天',
            '14d' => '14天',
            '30d' => '30天',
            'all' => '全部',
        ];
        foreach ($ranges as $value => $label):
        ?>
        <button
            type="button"
            data-range="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
            class="user-growth-range-btn rounded-full border px-3 py-1 text-sm transition-colors <?= $value === '24h' ? 'border-blue-600 bg-blue-50 text-blue-600 font-medium' : 'border-gray-200 text-gray-600 hover:bg-gray-50' ?>"
        >
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
            <p class="text-xs text-gray-500">本期新增用户</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900" id="user-growth-total">-</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
            <p class="text-xs text-gray-500">峰值时段</p>
            <p class="mt-1 text-sm font-medium text-gray-900" id="user-growth-peak-label">-</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
            <p class="text-xs text-gray-500">峰值新增</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900" id="user-growth-peak-count">-</p>
        </div>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <div class="relative h-[320px]">
            <canvas id="user-growth-chart" aria-label="用户增长趋势图"></canvas>
        </div>
        <p class="mt-3 text-xs text-gray-400" id="user-growth-empty" hidden>暂无注册数据，新用户注册后将自动记录。</p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const apiUrl = '../api/analytics_user_growth.php';
    const buttons = document.querySelectorAll('.user-growth-range-btn');
    const totalEl = document.getElementById('user-growth-total');
    const peakLabelEl = document.getElementById('user-growth-peak-label');
    const peakCountEl = document.getElementById('user-growth-peak-count');
    const emptyEl = document.getElementById('user-growth-empty');
    const canvas = document.getElementById('user-growth-chart');
    let currentRange = '24h';
    let chart = null;

    function setActiveButton(range) {
        buttons.forEach((btn) => {
            const active = btn.dataset.range === range;
            btn.classList.toggle('border-blue-600', active);
            btn.classList.toggle('bg-blue-50', active);
            btn.classList.toggle('text-blue-600', active);
            btn.classList.toggle('font-medium', active);
            btn.classList.toggle('border-gray-200', !active);
            btn.classList.toggle('text-gray-600', !active);
        });
    }

    function renderChart(data) {
        const labels = data.labels || [];
        const counts = data.counts || [];
        const hasData = (data.total || 0) > 0;

        totalEl.textContent = String(data.total || 0);
        peakLabelEl.textContent = data.peak ? data.peak.label : '-';
        peakCountEl.textContent = data.peak ? String(data.peak.count) : '-';
        emptyEl.hidden = hasData;
        canvas.hidden = !hasData;

        if (chart) {
            chart.destroy();
            chart = null;
        }

        if (!hasData) {
            return;
        }

        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: '新增用户',
                    data: counts,
                    borderColor: 'rgb(37, 99, 235)',
                    backgroundColor: 'rgba(37, 99, 235, 0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: labels.length > 30 ? 0 : 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `新增 ${ctx.parsed.y} 人`,
                        },
                    },
                },
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: labels.length > 24 ? 12 : 24,
                        },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
            },
        });
    }

    async function loadTrend(range) {
        currentRange = range;
        setActiveButton(range);

        try {
            const res = await fetch(`${apiUrl}?range=${encodeURIComponent(range)}`, {
                credentials: 'same-origin',
            });
            if (!res.ok) {
                throw new Error('请求失败');
            }
            const data = await res.json();
            renderChart(data);
        } catch (err) {
            totalEl.textContent = '-';
            peakLabelEl.textContent = '加载失败';
            peakCountEl.textContent = '-';
            emptyEl.hidden = false;
            emptyEl.textContent = '数据加载失败，请刷新后重试。';
            canvas.hidden = true;
        }
    }

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => loadTrend(btn.dataset.range));
    });

    loadTrend('24h');
})();
</script>
