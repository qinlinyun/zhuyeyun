/**
 * 登录页 Vue 动态背景（粒子连线 + 渐变光晕）
 */
(function () {
    const mountEl = document.getElementById('login-vue-bg-app');
    if (!mountEl || typeof Vue === 'undefined') return;

    const { createApp, ref, computed, onMounted, onUnmounted } = Vue;

    createApp({
        setup() {
            const canvasRef = ref(null);
            const isDark = ref(document.documentElement.classList.contains('dark'));
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            const overlayStyle = computed(() => ({
                background: isDark.value
                    ? 'radial-gradient(ellipse 80% 60% at 50% 0%, rgba(220,38,38,.12), transparent 55%), linear-gradient(to bottom, rgba(15,23,42,.35), rgba(2,6,23,.72))'
                    : 'radial-gradient(ellipse 80% 60% at 50% 0%, rgba(239,68,68,.08), transparent 55%), linear-gradient(to bottom, rgba(248,250,252,.15), rgba(241,245,249,.55))',
            }));

            let rafId = 0;
            let resizeObserver = null;
            let themeObserver = null;
            let onResize = null;

            onMounted(() => {
                const canvas = canvasRef.value;
                if (!canvas) return;
                const ctx = canvas.getContext('2d');
                if (!ctx) return;

                const palette = () => (isDark.value
                    ? { line: 'rgba(248,113,113,.14)', dot: 'rgba(252,165,165,.55)', glow: ['#7f1d1d', '#1e3a5f', '#14532d'] }
                    : { line: 'rgba(239,68,68,.12)', dot: 'rgba(220,38,38,.35)', glow: ['#fecaca', '#bae6fd', '#bbf7d0'] });

                const particleCount = () => {
                    const w = window.innerWidth;
                    if (w < 640) return reducedMotion ? 18 : 42;
                    if (w < 1024) return reducedMotion ? 28 : 64;
                    return reducedMotion ? 36 : 88;
                };

                let width = 0;
                let height = 0;
                let dpr = 1;
                let particles = [];
                let blobs = [];
                let tick = 0;

                function resize() {
                    dpr = Math.min(window.devicePixelRatio || 1, 2);
                    const rect = canvas.parentElement.getBoundingClientRect();
                    width = Math.max(1, Math.floor(rect.width));
                    height = Math.max(1, Math.floor(rect.height));
                    canvas.width = Math.floor(width * dpr);
                    canvas.height = Math.floor(height * dpr);
                    canvas.style.width = width + 'px';
                    canvas.style.height = height + 'px';
                    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
                    initParticles();
                    initBlobs();
                }

                function initParticles() {
                    const n = particleCount();
                    particles = Array.from({ length: n }, () => ({
                        x: Math.random() * width,
                        y: Math.random() * height,
                        vx: (Math.random() - 0.5) * (reducedMotion ? 0.15 : 0.45),
                        vy: (Math.random() - 0.5) * (reducedMotion ? 0.15 : 0.45),
                        r: Math.random() * 1.6 + 0.8,
                    }));
                }

                function initBlobs() {
                    blobs = [
                        { x: 0.18, y: 0.22, r: 0.42, hue: 0, phase: 0 },
                        { x: 0.82, y: 0.35, r: 0.38, hue: 210, phase: 1.4 },
                        { x: 0.55, y: 0.78, r: 0.36, hue: 140, phase: 2.8 },
                    ];
                }

                function drawBlobs() {
                    const p = palette();
                    blobs.forEach((b, i) => {
                        const t = tick * (reducedMotion ? 0.0004 : 0.0012) + b.phase;
                        const cx = (b.x + Math.sin(t + i) * 0.06) * width;
                        const cy = (b.y + Math.cos(t * 0.9 + i) * 0.05) * height;
                        const radius = Math.min(width, height) * (b.r + Math.sin(t * 1.1) * 0.04);
                        const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius);
                        g.addColorStop(0, p.glow[i % p.glow.length] + (isDark.value ? '55' : '88'));
                        g.addColorStop(1, 'transparent');
                        ctx.fillStyle = g;
                        ctx.fillRect(cx - radius, cy - radius, radius * 2, radius * 2);
                    });
                }

                function step() {
                    tick += 1;
                    ctx.clearRect(0, 0, width, height);
                    drawBlobs();

                    const p = palette();
                    const linkDist = width < 640 ? 95 : 130;

                    particles.forEach(pt => {
                        if (!reducedMotion) {
                            pt.x += pt.vx;
                            pt.y += pt.vy;
                            if (pt.x < 0 || pt.x > width) pt.vx *= -1;
                            if (pt.y < 0 || pt.y > height) pt.vy *= -1;
                        }
                    });

                    for (let i = 0; i < particles.length; i++) {
                        for (let j = i + 1; j < particles.length; j++) {
                            const a = particles[i];
                            const b = particles[j];
                            const dx = a.x - b.x;
                            const dy = a.y - b.y;
                            const dist = Math.hypot(dx, dy);
                            if (dist < linkDist) {
                                const alpha = (1 - dist / linkDist) * (isDark.value ? 0.22 : 0.16);
                                ctx.strokeStyle = isDark.value
                                    ? `rgba(248,113,113,${alpha.toFixed(3)})`
                                    : `rgba(239,68,68,${alpha.toFixed(3)})`;
                                ctx.lineWidth = 1;
                                ctx.beginPath();
                                ctx.moveTo(a.x, a.y);
                                ctx.lineTo(b.x, b.y);
                                ctx.stroke();
                            }
                        }
                    }

                    particles.forEach(pt => {
                        ctx.beginPath();
                        ctx.fillStyle = p.dot;
                        ctx.arc(pt.x, pt.y, pt.r, 0, Math.PI * 2);
                        ctx.fill();
                    });

                    if (!reducedMotion) {
                        rafId = requestAnimationFrame(step);
                    }
                }

                onResize = resize;
                resize();
                step();

                window.addEventListener('resize', onResize);
                resizeObserver = new ResizeObserver(onResize);
                resizeObserver.observe(canvas.parentElement);

                themeObserver = new MutationObserver(() => {
                    isDark.value = document.documentElement.classList.contains('dark');
                });
                themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            });

            onUnmounted(() => {
                cancelAnimationFrame(rafId);
                if (onResize) window.removeEventListener('resize', onResize);
                resizeObserver?.disconnect();
                themeObserver?.disconnect();
            });

            return { canvasRef, overlayStyle };
        },
        template: `
            <canvas ref="canvasRef" class="absolute inset-0 block h-full w-full"></canvas>
            <div class="pointer-events-none absolute inset-0 transition-[background] duration-500" :style="overlayStyle"></div>
        `,
    }).mount(mountEl);
})();
