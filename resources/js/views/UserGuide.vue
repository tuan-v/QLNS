<template>
    <div>
        <PageHeader
            title="Hướng dẫn sử dụng"
            subtitle="Cách dùng từng chức năng, sắp theo vai trò. Mặc định chỉ hiện những phần dành cho bạn."
        />

        <div class="toolbar mb-4">
            <v-text-field
                v-model="search"
                density="comfortable"
                hide-details
                clearable
                prepend-inner-icon="mdi-magnify"
                placeholder="Tìm hướng dẫn: chấm công, xin nghỉ, phiếu lương..."
                class="toolbar__search"
            />
            <v-chip-group v-model="roleFilter" mandatory column selected-class="text-primary">
                <v-chip value="mine" variant="outlined" filter>Dành cho tôi</v-chip>
                <v-chip value="all" variant="outlined" filter>Tất cả</v-chip>
                <v-chip v-for="(meta, key) in GUIDE_ROLES" :key="key" :value="key" variant="outlined" filter>
                    {{ meta.label }}
                </v-chip>
            </v-chip-group>
        </div>

        <div class="guide-layout">
            <!-- Mục lục -->
            <nav v-if="!mobile" class="toc glass-panel">
                <div v-for="chapter in chapters" :key="chapter.id" class="mb-3">
                    <div class="toc__chapter"><v-icon :icon="chapter.icon" size="16" />{{ chapter.title }}</div>
                    <a
                        v-for="article in chapter.articles"
                        :key="article.id"
                        :href="`#${article.id}`"
                        class="toc__link"
                        :class="{ 'toc__link--active': activeId === article.id }"
                        @click.prevent="goTo(article.id)"
                    >
                        {{ article.title }}
                    </a>
                </div>
            </nav>
            <v-select
                v-else
                :model-value="activeId"
                :items="tocOptions"
                density="comfortable"
                hide-details
                placeholder="Đi tới mục..."
                prepend-inner-icon="mdi-format-list-bulleted"
                class="mb-3"
                @update:model-value="goTo"
            />

            <!-- Nội dung -->
            <div class="min-width-0">
                <template v-if="chapters.length">
                    <section v-for="chapter in chapters" :key="chapter.id" class="mb-6">
                        <h2 class="chapter-title">
                            <v-icon :icon="chapter.icon" size="20" color="primary" />{{ chapter.title }}
                        </h2>
                        <article
                            v-for="article in chapter.articles"
                            :id="article.id"
                            :key="article.id"
                            class="article glass-panel"
                            :class="{ 'article--highlight': highlightId === article.id }"
                        >
                            <div class="d-flex align-start ga-3">
                                <v-avatar color="primary" variant="tonal" rounded="lg" size="40">
                                    <v-icon :icon="article.icon" />
                                </v-avatar>
                                <div class="flex-grow-1 min-width-0">
                                    <h3 class="article__title">{{ article.title }}</h3>
                                    <div class="d-flex flex-wrap ga-1 mt-1">
                                        <v-chip
                                            v-for="role in article.roles"
                                            :key="role"
                                            size="x-small"
                                            :color="GUIDE_ROLES[role].color"
                                            variant="tonal"
                                            :prepend-icon="GUIDE_ROLES[role].icon"
                                        >
                                            {{ GUIDE_ROLES[role].label }}
                                        </v-chip>
                                    </div>
                                </div>
                                <v-btn
                                    v-if="canOpen(article)"
                                    variant="tonal"
                                    color="primary"
                                    size="small"
                                    append-icon="mdi-arrow-right"
                                    :to="{ name: article.route }"
                                    class="flex-shrink-0 article__open"
                                >
                                    Mở trang
                                </v-btn>
                            </div>
                            <p class="article__summary">{{ article.summary }}</p>
                            <ol class="steps">
                                <li v-for="(step, index) in article.steps" :key="index">{{ step }}</li>
                            </ol>
                            <div v-if="article.notes?.length" class="notes">
                                <v-icon
                                    icon="mdi-lightbulb-on-outline"
                                    size="18"
                                    color="warning"
                                    class="flex-shrink-0"
                                />
                                <ul>
                                    <li v-for="(note, index) in article.notes" :key="index">{{ note }}</li>
                                </ul>
                            </div>
                        </article>
                    </section>
                </template>
                <div v-else class="empty glass-panel">
                    <v-icon icon="mdi-text-search" size="32" class="mb-1" />
                    <div>Không tìm thấy hướng dẫn phù hợp.</div>
                    <v-btn variant="text" color="primary" class="mt-2" @click="resetFilters"
                        >Xem tất cả hướng dẫn</v-btn
                    >
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useDisplay } from "vuetify";
import PageHeader from "../components/common/PageHeader.vue";
import { GUIDE_CHAPTERS, GUIDE_ROLES } from "../content/userGuide";
import { normalizeVietnamese } from "../composables/normalizeVietnamese";
import { useAuthStore } from "../stores/authStore";

// Hướng dẫn sử dụng trong ứng dụng (2026-10-15) — nội dung ở content/userGuide.js.
// ?muc=<id bài> mở thẳng 1 bài (để nút "?" trên từng trang trỏ về sau này).
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { mobile } = useDisplay();

const search = ref("");
const roleFilter = ref("mine");
const activeId = ref(null);
const highlightId = ref(null);

const has = (code) => auth.permissions.includes(code);
// Vai trò của người xem — ai cũng là nhân viên và có thể là Lead của dự án nào đó.
const myRoles = computed(() => {
    const roles = ["employee", "lead"];
    if (has("employee.view") || has("payroll.view_all") || has("leave.view_all")) roles.push("hr");
    if (has("rbac.manage")) roles.push("admin");
    return roles;
});

const chapters = computed(() => {
    const keyword = normalizeVietnamese((search.value ?? "").trim());
    const roles = roleFilter.value === "mine" ? myRoles.value : roleFilter.value === "all" ? null : [roleFilter.value];

    return GUIDE_CHAPTERS.map((chapter) => ({
        ...chapter,
        articles: chapter.articles.filter((article) => {
            if (roles && !article.roles.some((r) => roles.includes(r))) return false;
            if (!keyword) return true;
            const text = [
                article.title,
                article.summary,
                ...article.steps,
                ...(article.notes ?? []),
                ...(article.keywords ?? []),
            ].join(" ");
            return normalizeVietnamese(text).includes(keyword);
        }),
    })).filter((chapter) => chapter.articles.length);
});

const tocOptions = computed(() =>
    chapters.value.flatMap((c) => c.articles.map((a) => ({ title: `${c.title} — ${a.title}`, value: a.id }))),
);

function canOpen(article) {
    if (!article.route) return false;
    return !article.permission || has(article.permission);
}

async function goTo(id) {
    if (!id) return;
    activeId.value = id;
    await nextTick();
    document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
    highlightId.value = id;
    setTimeout(() => (highlightId.value === id ? (highlightId.value = null) : null), 1600);
}

function resetFilters() {
    search.value = "";
    roleFilter.value = "all";
}

onMounted(() => {
    const target = route.query.muc;
    if (typeof target === "string") {
        // Bài được trỏ tới có thể không thuộc vai trò của người xem -> mở "Tất cả".
        if (!chapters.value.some((c) => c.articles.some((a) => a.id === target))) roleFilter.value = "all";
        goTo(target);
        const { muc: _muc, ...rest } = route.query;
        router.replace({ query: rest });
    }
});
</script>

<style scoped>
.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 16px;
}
.toolbar__search {
    flex: 1 1 280px;
    max-width: 440px;
}
.guide-layout {
    display: grid;
    grid-template-columns: 270px minmax(0, 1fr);
    gap: 20px;
    align-items: start;
}
.toc {
    position: sticky;
    top: 80px;
    max-height: calc(100vh - 100px);
    overflow-y: auto;
    padding: 16px;
    border: 1px solid rgb(var(--v-theme-hairline));
}
.toc__chapter {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 4px;
}
.toc__link {
    display: block;
    padding: 5px 10px;
    border-radius: 8px;
    font-size: 0.875rem;
    line-height: 1.35;
    color: rgb(var(--v-theme-ink));
    text-decoration: none;
}
.toc__link:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}
.toc__link--active {
    color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.08);
    font-weight: 600;
}
.chapter-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1.125rem;
    font-weight: 700;
    color: rgb(var(--v-theme-ink));
    margin-bottom: 12px;
}
.article {
    padding: 20px;
    border: 1px solid rgb(var(--v-theme-hairline));
    margin-bottom: 12px;
    scroll-margin-top: 80px;
    transition:
        box-shadow 0.3s ease,
        border-color 0.3s ease;
}
.article--highlight {
    border-color: rgb(var(--v-theme-primary));
    box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.15) !important;
}
.article__title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
    color: rgb(var(--v-theme-ink));
}
.article__summary {
    margin: 12px 0 8px;
    color: rgb(var(--v-theme-ink-muted));
}
/* Reset CSS của Vuetify xóa số thứ tự của <ol> — tự vẽ lại bằng counter. */
.steps {
    list-style: none;
    counter-reset: step;
    padding-left: 0;
    line-height: 1.6;
}
.steps li {
    counter-increment: step;
    position: relative;
    padding-left: 34px;
}
.steps li::before {
    content: counter(step);
    position: absolute;
    left: 0;
    top: 1px;
    width: 22px;
    height: 22px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.1);
}
.steps li + li {
    margin-top: 8px;
}
.notes {
    display: flex;
    gap: 10px;
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    background: rgba(var(--v-theme-warning), 0.07);
    font-size: 0.875rem;
}
.notes ul {
    list-style: disc;
    padding-left: 16px;
    margin: 0;
}
.notes li + li {
    margin-top: 4px;
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 48px 16px;
    border: 1px dashed rgb(var(--v-theme-hairline));
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 960px) {
    .guide-layout {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 600px) {
    .article {
        padding: 16px;
    }
    .article__open {
        display: none;
    }
}
</style>
