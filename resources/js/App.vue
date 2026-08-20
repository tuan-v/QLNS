<script setup>
import { version as vueVersion } from 'vue';
</script>

<template>
    <main class="launch-page">
        <section class="launch-card" aria-labelledby="launch-title">
            <span class="status-badge">
                <span class="status-dot" aria-hidden="true"></span>
                Đang hoạt động
            </span>

            <p class="eyebrow">Dự án quản lý nhân sự</p>
            <h1 id="launch-title">Vue 3 đã khởi động thành công</h1>
            <p class="description">
                Laravel đang cung cấp trang nền và Vite đang biên dịch ứng dụng Vue.
                Bộ khung frontend cho Ngày 2 đã sẵn sàng.
            </p>

            <dl class="runtime-list">
                <div>
                    <dt>Frontend</dt>
                    <dd>Vue {{ vueVersion }}</dd>
                </div>
                <div>
                    <dt>Bundler</dt>
                    <dd>Vite</dd>
                </div>
                <div>
                    <dt>Backend</dt>
                    <dd>Laravel 12</dd>
                </div>
            </dl>
        </section>
    </main>
</template>

<style scoped>
.launch-page {
    display: grid;
    min-height: 100vh;
    place-items: center;
    padding: 2rem;
    background:
        radial-gradient(circle at top left, hsl(240 59% 66% / 28%), transparent 34rem),
        hsl(222 47% 11%);
    color: hsl(210 40% 98%);
}

.launch-card {
    width: min(100%, 46rem);
    padding: clamp(2rem, 6vw, 4rem);
    border: 1px solid hsl(215 25% 75% / 18%);
    border-radius: 1.5rem;
    background: hsl(222 40% 16% / 76%);
    box-shadow: 0 1.5rem 5rem hsl(222 70% 4% / 45%);
    backdrop-filter: blur(12px);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.75rem;
    border: 1px solid hsl(158 64% 52% / 32%);
    border-radius: 999px;
    background: hsl(158 64% 52% / 10%);
    color: hsl(158 70% 72%);
    font-size: 0.875rem;
    font-weight: 600;
}

.status-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
    background: hsl(158 64% 52%);
    box-shadow: 0 0 0.75rem hsl(158 64% 52% / 70%);
}

.eyebrow {
    margin-top: 2rem;
    color: hsl(240 72% 78%);
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

h1 {
    max-width: 13ch;
    margin-top: 0.75rem;
    font-size: clamp(2.25rem, 7vw, 4.25rem);
    font-weight: 700;
    letter-spacing: -0.045em;
    line-height: 1.02;
}

.description {
    max-width: 38rem;
    margin-top: 1.5rem;
    color: hsl(215 20% 75%);
    font-size: 1.05rem;
    line-height: 1.75;
}

.runtime-list {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-top: 2.5rem;
}

.runtime-list div {
    padding: 1rem;
    border: 1px solid hsl(215 25% 75% / 12%);
    border-radius: 0.875rem;
    background: hsl(222 47% 11% / 50%);
}

dt {
    color: hsl(215 16% 58%);
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

dd {
    margin-top: 0.35rem;
    font-weight: 600;
}

@media (max-width: 40rem) {
    .launch-page {
        padding: 1rem;
    }

    .runtime-list {
        grid-template-columns: 1fr;
    }
}
</style>
