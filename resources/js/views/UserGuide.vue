<template>
    <div>
        <PageHeader title="Hướng dẫn sử dụng" subtitle="Cách dùng các chức năng của hệ thống QLNS." />

        <div class="guide">
            <!-- Mục lục -->
            <aside v-if="!mobile" class="toc">
                <a href="#" class="toc__home" :class="{ active: !article }" @click.prevent="openHome">Tất cả chủ đề</a>
                <div v-for="chapter in chapters" :key="chapter.id" class="toc__group">
                    <div class="toc__heading">{{ chapter.title }}</div>
                    <a
                        v-for="item in chapter.articles"
                        :key="item.id"
                        href="#"
                        class="toc__link"
                        :class="{ active: article?.id === item.id }"
                        @click.prevent="openArticle(item.id)"
                    >
                        {{ item.title }}
                    </a>
                </div>
                <div class="toc__filter">
                    <label for="guide-role">Hiển thị</label>
                    <select id="guide-role" v-model="roleFilter">
                        <option v-for="option in roleOptions" :key="option.value" :value="option.value">
                            {{ option.title }}
                        </option>
                    </select>
                </div>
            </aside>

            <main class="doc glass-panel">
                <!-- Danh sách chủ đề -->
                <template v-if="!article">
                    <v-text-field
                        v-model="search"
                        density="comfortable"
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-magnify"
                        placeholder="Tìm trong hướng dẫn"
                        class="doc__search"
                    />

                    <template v-if="keyword">
                        <p class="doc__muted">{{ results.length }} bài khớp với “{{ search }}”.</p>
                        <ul class="plain-list">
                            <li v-for="item in results" :key="item.id">
                                <a href="#" @click.prevent="openArticle(item.id)">{{ item.title }}</a>
                                <span class="doc__muted"> ({{ item.chapter.title }})</span>
                                <div class="doc__muted small">{{ item.summary }}</div>
                            </li>
                        </ul>
                        <p v-if="!results.length" class="doc__muted">
                            Không có bài nào. Thử gõ ngắn hơn, hoặc chọn hiển thị tất cả vai trò.
                        </p>
                    </template>

                    <template v-else>
                        <h2 v-if="quickTasks.length" class="doc__h2">Thường dùng</h2>
                        <ul v-if="quickTasks.length" class="plain-list plain-list--columns">
                            <li v-for="item in quickTasks" :key="item.id">
                                <a href="#" @click.prevent="openArticle(item.id)">{{ item.title }}</a>
                            </li>
                        </ul>

                        <h2 class="doc__h2">Chủ đề</h2>
                        <div class="topics">
                            <section v-for="chapter in chapters" :key="chapter.id" class="topic">
                                <h3 class="topic__title">{{ chapter.title }}</h3>
                                <p class="doc__muted small">{{ chapter.description }}</p>
                                <ul class="plain-list">
                                    <li v-for="item in chapter.articles" :key="item.id">
                                        <a href="#" @click.prevent="openArticle(item.id)">{{ item.title }}</a>
                                    </li>
                                </ul>
                            </section>
                        </div>
                    </template>

                    <div v-if="mobile" class="mobile-filter">
                        <v-select
                            v-model="roleFilter"
                            :items="roleOptions"
                            label="Hiển thị"
                            density="compact"
                            hide-details
                        />
                    </div>
                </template>

                <!-- Một bài -->
                <article v-else :key="article.id">
                    <p class="crumbs">
                        <a href="#" @click.prevent="openHome">Hướng dẫn</a>
                        <span> / {{ article.chapter.title }}</span>
                    </p>
                    <h1 class="doc__h1">{{ article.title }}</h1>
                    <p class="doc__muted small">
                        Dành cho: {{ article.roles.map((r) => GUIDE_ROLES[r].label).join(", ") }}
                    </p>

                    <p class="doc__lead">{{ article.summary }}</p>

                    <h2 class="doc__h2">Cách làm</h2>
                    <ol class="steps">
                        <li v-for="(step, index) in article.steps" :key="index">{{ step }}</li>
                    </ol>

                    <template v-if="article.notes?.length">
                        <h2 class="doc__h2">Lưu ý</h2>
                        <ul class="notes">
                            <li v-for="(note, index) in article.notes" :key="index">{{ note }}</li>
                        </ul>
                    </template>

                    <p v-if="canOpen(article)" class="mt-6">
                        <v-btn variant="flat" :to="{ name: article.route }" append-icon="mdi-arrow-right">
                            Đi tới trang {{ routeTitle(article.route) }}
                        </v-btn>
                    </p>

                    <nav class="pager">
                        <a v-if="neighbours.prev" href="#" @click.prevent="openArticle(neighbours.prev.id)">
                            ← {{ neighbours.prev.title }}
                        </a>
                        <span v-else />
                        <a
                            v-if="neighbours.next"
                            href="#"
                            class="text-right"
                            @click.prevent="openArticle(neighbours.next.id)"
                        >
                            {{ neighbours.next.title }} →
                        </a>
                    </nav>
                </article>
            </main>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useDisplay } from "vuetify";
import PageHeader from "../components/common/PageHeader.vue";
import { GUIDE_CHAPTERS, GUIDE_QUICK_TASKS, GUIDE_ROLES } from "../content/userGuide";
import { normalizeVietnamese } from "../composables/normalizeVietnamese";
import { useAuthStore } from "../stores/authStore";

// Hướng dẫn sử dụng (2026-10-15). Làm lại 2026-10-16 theo phản hồi: lần 1 "khó đọc" -> đọc từng
// bài; lần 2 "trông như AI làm" -> bỏ gradient, icon màu, lưới thẻ; trình bày như trang tài liệu
// nội bộ (chữ là chính, danh sách đánh số thường). Bài đang đọc nằm trên URL (?muc=<id>).
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { mobile } = useDisplay();

const search = ref("");
const roleFilter = ref("mine");

const has = (code) => auth.permissions.includes(code);
// Ai cũng là nhân viên và có thể là Lead dự án nào đó.
const myRoles = computed(() => {
    const roles = ["employee", "lead"];
    if (has("employee.view") || has("payroll.view_all") || has("leave.view_all")) roles.push("hr");
    if (has("rbac.manage")) roles.push("admin");
    return roles;
});

const roleOptions = [
    { title: "Phần dành cho tôi", value: "mine" },
    { title: "Tất cả vai trò", value: "all" },
    ...Object.entries(GUIDE_ROLES).map(([value, meta]) => ({ title: `Chỉ ${meta.label}`, value })),
];

const ROUTE_TITLES = Object.fromEntries(router.getRoutes().map((r) => [r.name, r.meta?.title]));
const routeTitle = (name) => ROUTE_TITLES[name] ?? "";

const allArticles = GUIDE_CHAPTERS.flatMap((chapter) => chapter.articles.map((a) => ({ ...a, chapter })));

const visibleRoles = computed(() =>
    roleFilter.value === "mine" ? myRoles.value : roleFilter.value === "all" ? null : [roleFilter.value],
);
const isVisible = (a) => !visibleRoles.value || a.roles.some((r) => visibleRoles.value.includes(r));

const chapters = computed(() =>
    GUIDE_CHAPTERS.map((chapter) => ({
        ...chapter,
        articles: chapter.articles.filter(isVisible).map((a) => ({ ...a, chapter })),
    })).filter((c) => c.articles.length),
);
const visibleList = computed(() => chapters.value.flatMap((c) => c.articles));

const keyword = computed(() => normalizeVietnamese((search.value ?? "").trim()));
// Tìm theo từng từ (không cần đứng liền nhau); bài khớp ở tiêu đề xếp lên trước.
const results = computed(() => {
    const words = keyword.value.split(/\s+/).filter(Boolean);
    return visibleList.value
        .map((a) => {
            const title = normalizeVietnamese(a.title);
            const text = normalizeVietnamese(
                [a.title, a.summary, ...a.steps, ...(a.notes ?? []), ...(a.keywords ?? [])].join(" "),
            );
            if (!words.every((w) => text.includes(w))) return null;
            return { article: a, score: words.filter((w) => title.includes(w)).length };
        })
        .filter(Boolean)
        .sort((x, y) => y.score - x.score)
        .map((r) => r.article);
});

const quickTasks = computed(() => {
    const ids = [...new Set(myRoles.value.flatMap((r) => GUIDE_QUICK_TASKS[r] ?? []))];
    return ids
        .map((id) => allArticles.find((a) => a.id === id))
        .filter(Boolean)
        .slice(0, 8);
});

const article = computed(() => allArticles.find((a) => a.id === route.query.muc) ?? null);

// Bài trước / tiếp trong danh sách đang hiển thị (bài mở ngoài bộ lọc thì theo toàn bộ).
const neighbours = computed(() => {
    const list = visibleList.value.some((a) => a.id === article.value?.id) ? visibleList.value : allArticles;
    const index = list.findIndex((a) => a.id === article.value?.id);
    return { prev: list[index - 1] ?? null, next: list[index + 1] ?? null };
});

function canOpen(item) {
    return !!item.route && (!item.permission || has(item.permission));
}

function openArticle(id) {
    router.push({ query: { ...route.query, muc: id } });
    window.scrollTo({ top: 0 });
}

function openHome() {
    const { muc: _muc, ...rest } = route.query;
    router.push({ query: rest });
}
</script>

<style scoped>
.guide {
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 32px;
    align-items: start;
}

/* Mục lục: chỉ có chữ, như sidebar của một trang tài liệu */
.toc {
    position: sticky;
    top: 80px;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
    font-size: 0.875rem;
    padding-right: 8px;
}
.toc a {
    display: block;
    text-decoration: none;
    color: rgb(var(--v-theme-ink-muted));
    padding: 3px 0 3px 10px;
    border-left: 2px solid transparent;
    line-height: 1.45;
}
.toc a:hover {
    color: rgb(var(--v-theme-ink));
}
.toc a.active {
    color: rgb(var(--v-theme-primary));
    border-left-color: rgb(var(--v-theme-primary));
    font-weight: 500;
}
.toc__home {
    margin-bottom: 12px;
}
.toc__group {
    margin-bottom: 14px;
}
.toc__heading {
    font-weight: 600;
    color: rgb(var(--v-theme-ink));
    padding-left: 10px;
    margin-bottom: 2px;
}
.toc__filter {
    margin-top: 20px;
    padding-left: 10px;
    color: rgb(var(--v-theme-ink-muted));
}
.toc__filter label {
    display: block;
    font-size: 12px;
    margin-bottom: 4px;
}
.toc__filter select {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 6px;
    color: rgb(var(--v-theme-ink));
    background: rgb(var(--v-theme-surface));
}

/* Nội dung */
.doc {
    padding: 28px 40px 36px;
    border: 1px solid rgb(var(--v-theme-hairline));
    max-width: 860px;
    color: rgb(var(--v-theme-ink));
    font-size: 0.9375rem;
    line-height: 1.7;
}
.doc a {
    color: rgb(var(--v-theme-primary));
    text-decoration: none;
}
.doc a:hover {
    text-decoration: underline;
}
.doc__search {
    max-width: 420px;
    margin-bottom: 8px;
}
.doc__h1 {
    font-size: 1.5rem;
    font-weight: 700;
    line-height: 1.35;
    margin: 4px 0 2px;
}
.doc__h2 {
    font-size: 1.0625rem;
    font-weight: 600;
    margin: 28px 0 8px;
}
.doc__lead {
    margin-top: 16px;
    font-size: 1rem;
}
.doc__muted {
    color: rgb(var(--v-theme-ink-muted));
}
.small {
    font-size: 0.8125rem;
}
.crumbs {
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 8px;
}
.plain-list {
    list-style: disc;
    padding-left: 20px;
    margin: 0;
}
.plain-list li + li {
    margin-top: 4px;
}
.plain-list--columns {
    columns: 2;
    column-gap: 32px;
}
.plain-list--columns li {
    break-inside: avoid;
}
.topics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px 40px;
}
.topic {
    padding-top: 12px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.topic__title {
    font-size: 0.9375rem;
    font-weight: 600;
}
.steps {
    list-style: decimal;
    padding-left: 22px;
    margin: 0;
}
.steps li {
    padding-left: 4px;
}
.steps li + li {
    margin-top: 8px;
}
.notes {
    list-style: disc;
    padding-left: 20px;
    margin: 0;
    color: rgb(var(--v-theme-ink));
}
.notes li + li {
    margin-top: 6px;
}
.pager {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    margin-top: 36px;
    padding-top: 16px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
    font-size: 0.875rem;
}
.mobile-filter {
    margin-top: 28px;
}

@media (max-width: 960px) {
    .guide {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 600px) {
    .doc {
        padding: 20px 18px 24px;
    }
    .topics {
        grid-template-columns: minmax(0, 1fr);
    }
    .plain-list--columns {
        columns: 1;
    }
    .pager {
        flex-direction: column;
        gap: 10px;
    }
}
</style>
