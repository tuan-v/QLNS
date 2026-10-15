<template>
    <div>
        <PageHeader
            title="Nhật ký hoạt động"
            subtitle="Ai đã thêm, sửa, xóa dữ liệu gì và lịch sử đăng nhập. Nhật ký được lưu 24 tháng."
        />

        <!-- Bộ lọc -->
        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="filters">
                <v-text-field
                    v-model="search"
                    density="compact"
                    hide-details
                    clearable
                    prepend-inner-icon="mdi-magnify"
                    placeholder="Tìm theo nội dung, email, IP..."
                    class="filters__search"
                />
                <SearchSelect
                    v-model="userId"
                    :items="userOptions"
                    density="compact"
                    hide-details
                    clearable
                    placeholder="Mọi người thực hiện"
                    class="filters__select"
                />
                <SearchSelect
                    v-model="module"
                    :items="moduleOptions"
                    density="compact"
                    hide-details
                    clearable
                    placeholder="Mọi loại dữ liệu"
                    class="filters__select"
                />
                <div class="filters__date">
                    <InputDate
                        v-model="dateFrom"
                        :max="dateTo || todayIso()"
                        placeholder="Từ ngày"
                        hide-details
                        clearable
                    />
                </div>
                <div class="filters__date">
                    <InputDate
                        v-model="dateTo"
                        :min="dateFrom || undefined"
                        :max="todayIso()"
                        placeholder="Đến ngày"
                        hide-details
                        clearable
                    />
                </div>
            </div>
            <v-chip-group v-model="actions" multiple column selected-class="text-primary" class="mt-2">
                <v-chip
                    v-for="option in ACTION_OPTIONS"
                    :key="option.value"
                    :value="option.value"
                    variant="outlined"
                    filter
                    size="small"
                >
                    <v-icon
                        :icon="ACTION_META[option.value].icon"
                        :color="ACTION_META[option.value].color"
                        size="16"
                        class="mr-1"
                    />
                    {{ option.label }}
                </v-chip>
            </v-chip-group>
        </v-sheet>

        <v-alert v-if="store.loadError" type="error" variant="tonal" density="compact" class="mb-4">{{
            store.loadError
        }}</v-alert>

        <v-sheet class="border rounded-lg glass-panel pa-0" color="transparent">
            <div class="d-flex align-center px-5 py-3">
                <span class="text-body-2 text-medium-emphasis"
                    >{{ store.meta.total.toLocaleString("vi-VN") }} dòng nhật ký</span
                >
                <v-spacer />
                <v-btn variant="text" size="small" prepend-icon="mdi-refresh" :loading="store.loading" @click="load"
                    >Tải lại</v-btn
                >
            </div>
            <v-progress-linear v-if="store.loading" indeterminate color="primary" height="2" />
            <v-divider />

            <template v-if="store.logs.length">
                <section v-for="group in groups" :key="group.date">
                    <div class="day-head px-5 py-2">{{ group.label }}</div>
                    <div
                        v-for="log in group.logs"
                        :key="log.id"
                        class="log-row px-5 py-3"
                        role="button"
                        tabindex="0"
                        @click="openDetail(log.id)"
                        @keydown.enter="openDetail(log.id)"
                    >
                        <div class="log-row__time">{{ time(log.created_at) }}</div>
                        <v-avatar
                            :color="ACTION_META[log.action]?.color ?? 'grey'"
                            variant="tonal"
                            size="32"
                            rounded="lg"
                        >
                            <v-icon :icon="ACTION_META[log.action]?.icon ?? 'mdi-circle-small'" size="18" />
                        </v-avatar>
                        <div class="min-width-0 flex-grow-1">
                            <div class="text-body-2">
                                <span class="font-weight-bold">{{ log.actor?.name ?? "Hệ thống" }}</span
                                ><span class="text-medium-emphasis">{{ " " + verb(log) + " " }}</span
                                ><template v-if="log.module_type !== 'auth'"
                                    ><span class="font-weight-medium">{{ log.module }}</span
                                    >{{ " “" + log.subject + "”" }}</template
                                >
                            </div>
                            <div class="text-caption text-medium-emphasis text-truncate">
                                {{ log.summary
                                }}<template v-if="log.module_type === 'auth'"> · {{ log.subject }}</template>
                            </div>
                        </div>
                        <span class="log-row__ip text-caption text-medium-emphasis">{{ log.ip_address }}</span>
                        <v-icon icon="mdi-chevron-right" size="18" class="text-medium-emphasis" />
                    </div>
                </section>
            </template>
            <div v-else-if="!store.loading" class="empty">
                <v-icon icon="mdi-history" size="32" class="mb-1" />
                <div>Không có nhật ký nào khớp bộ lọc.</div>
            </div>

            <div v-if="store.meta.last_page > 1" class="d-flex justify-center py-3 border-t">
                <v-pagination
                    v-model="page"
                    :length="store.meta.last_page"
                    :total-visible="mobile ? 3 : 7"
                    density="comfortable"
                    rounded="lg"
                />
            </div>
        </v-sheet>

        <!-- Chi tiết 1 dòng nhật ký -->
        <v-dialog v-model="detailOpen" max-width="820" scrollable :fullscreen="mobile">
            <v-card :rounded="mobile ? 0 : 'xl'">
                <template v-if="detail">
                    <div class="d-flex align-start ga-3 px-6 pt-5 pb-4">
                        <v-avatar
                            :color="ACTION_META[detail.action]?.color ?? 'grey'"
                            variant="tonal"
                            size="44"
                            rounded="lg"
                        >
                            <v-icon :icon="ACTION_META[detail.action]?.icon" size="22" />
                        </v-avatar>
                        <div class="min-width-0 flex-grow-1">
                            <div class="text-caption text-medium-emphasis">
                                {{ detail.module }} · {{ detail.action_label }}
                            </div>
                            <div class="text-h6 font-weight-bold text-wrap" style="line-height: 1.35">
                                {{ detail.subject }}
                            </div>
                            <div class="text-body-2 text-medium-emphasis">{{ detail.summary }}</div>
                        </div>
                        <v-btn icon="mdi-close" variant="text" size="small" @click="detailOpen = false" />
                    </div>
                    <v-divider />
                    <v-card-text class="px-6 py-5">
                        <div class="meta-grid mb-5">
                            <div class="meta-label">Người thực hiện</div>
                            <div>
                                {{
                                    detail.actor ? `${detail.actor.name} (${detail.actor.email})` : "Hệ thống (tự động)"
                                }}
                            </div>
                            <div class="meta-label">Thời gian</div>
                            <div>{{ dateTime(detail.created_at) }}</div>
                            <div class="meta-label">Địa chỉ IP</div>
                            <div>{{ detail.ip_address ?? "—" }}</div>
                            <div class="meta-label">Thiết bị</div>
                            <div>{{ device(detail.user_agent) }}</div>
                            <template v-if="detail.url">
                                <div class="meta-label">Thao tác</div>
                                <div class="text-truncate" :title="detail.url">
                                    <span class="method-tag">{{ detail.http_method }}</span> {{ path(detail.url) }}
                                </div>
                            </template>
                        </div>

                        <template v-if="detail.changes.length">
                            <div class="section-label">Thay đổi</div>
                            <div class="diff-table mb-5">
                                <div class="diff-head">Trường</div>
                                <div class="diff-head">Trước</div>
                                <div class="diff-head">Sau</div>
                                <template v-for="change in detail.changes" :key="change.field">
                                    <div class="diff-cell diff-cell--label">{{ change.label }}</div>
                                    <div class="diff-cell diff-cell--before">
                                        {{ format(change.before, change.field) }}
                                    </div>
                                    <div class="diff-cell diff-cell--after">
                                        {{ format(change.after, change.field) }}
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template v-if="detail.snapshot.length">
                            <div class="section-label">
                                {{ detail.action === "deleted" ? "Dữ liệu trước khi xóa" : "Dữ liệu" }}
                            </div>
                            <div class="snapshot mb-5">
                                <template v-for="item in detail.snapshot" :key="item.field">
                                    <div class="snapshot__label">{{ item.label }}</div>
                                    <div class="snapshot__value">{{ format(item.value, item.field) }}</div>
                                </template>
                            </div>
                        </template>

                        <template v-if="detail.related.length">
                            <div class="section-label">Cùng lần thao tác</div>
                            <div class="related">
                                <button
                                    v-for="item in detail.related"
                                    :key="item.id"
                                    type="button"
                                    class="related__item"
                                    @click="openDetail(item.id)"
                                >
                                    <v-icon
                                        :icon="ACTION_META[item.action]?.icon"
                                        :color="ACTION_META[item.action]?.color"
                                        size="16"
                                    />
                                    <span class="font-weight-medium">{{ item.action_label }} {{ item.module }}</span>
                                    <span class="text-medium-emphasis text-truncate"
                                        >“{{ item.subject }}” · {{ item.summary }}</span
                                    >
                                </button>
                            </div>
                        </template>
                        <div class="text-caption text-medium-emphasis mt-4">
                            CCCD, mã số thuế và số tài khoản được che, chỉ hiện 4 số cuối.
                        </div>
                    </v-card-text>
                </template>
                <v-card-text v-else class="pa-8 text-center">
                    <v-progress-circular indeterminate color="primary" />
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useDisplay } from "vuetify";
import PageHeader from "../components/common/PageHeader.vue";
import SearchSelect from "../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../components/common/InputDate.vue";
import { useLogStore } from "../stores/logStore";
import { useToastStore } from "../stores/useToastStore";

const store = useLogStore();
const toast = useToastStore();
const { mobile } = useDisplay();

const search = ref("");
const userId = ref(null);
const module = ref(null);
const dateFrom = ref("");
const dateTo = ref("");
const actions = ref([]);
const page = ref(1);
const detailOpen = ref(false);
const detail = ref(null);

const ACTION_META = {
    created: { icon: "mdi-plus", color: "success", verb: "đã thêm" },
    updated: { icon: "mdi-pencil-outline", color: "primary", verb: "đã sửa" },
    deleted: { icon: "mdi-delete-outline", color: "error", verb: "đã xóa" },
    login: { icon: "mdi-login", color: "info", verb: "đăng nhập" },
    login_failed: { icon: "mdi-alert-outline", color: "warning", verb: "đăng nhập sai" },
    login_locked: { icon: "mdi-lock-alert-outline", color: "error", verb: "bị khóa" },
    logout: { icon: "mdi-logout", color: "secondary", verb: "đăng xuất" },
};
const ACTION_OPTIONS = [
    { value: "created", label: "Thêm" },
    { value: "updated", label: "Sửa" },
    { value: "deleted", label: "Xóa" },
    { value: "login", label: "Đăng nhập" },
    { value: "login_failed", label: "Đăng nhập sai" },
    { value: "login_locked", label: "Bị khóa" },
    { value: "logout", label: "Đăng xuất" },
];

const userOptions = computed(() => [
    { title: "Hệ thống (tự động)", value: "system" },
    ...store.filterOptions.users.map((u) => ({ title: `${u.name} (${u.email})`, value: String(u.id) })),
]);
const moduleOptions = computed(() => store.filterOptions.modules.map((m) => ({ title: m.label, value: m.value })));

// Nhóm theo ngày: Hôm nay / Hôm qua / dd/mm/yyyy.
const groups = computed(() => {
    const map = new Map();
    for (const log of store.logs) {
        const key = new Date(log.created_at).toLocaleDateString("en-CA");
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(log);
    }
    const today = new Date().toLocaleDateString("en-CA");
    const yesterday = new Date(Date.now() - 86400000).toLocaleDateString("en-CA");
    return [...map.entries()].map(([date, logs]) => ({
        date,
        label:
            date === today
                ? "Hôm nay"
                : date === yesterday
                  ? "Hôm qua"
                  : new Date(`${date}T00:00:00`).toLocaleDateString("vi-VN", {
                        weekday: "long",
                        day: "2-digit",
                        month: "2-digit",
                        year: "numeric",
                    }),
        logs,
    }));
});

function verb(log) {
    if (log.module_type === "auth") return ACTION_META[log.action]?.verb ?? log.action_label;
    return ACTION_META[log.action]?.verb ?? log.action_label.toLowerCase();
}

const time = (value) => new Date(value).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
const dateTime = (value) => new Date(value).toLocaleString("vi-VN");

function path(url) {
    try {
        return new URL(url).pathname;
    } catch {
        return url;
    }
}

// Tóm tắt trình duyệt / hệ điều hành từ user agent (không cần thư viện).
function device(ua) {
    if (!ua) return "—";
    const browser = /Edg\//.test(ua)
        ? "Edge"
        : /Chrome\//.test(ua)
          ? "Chrome"
          : /Firefox\//.test(ua)
            ? "Firefox"
            : /Safari\//.test(ua)
              ? "Safari"
              : "Khác";
    const os = /Windows/.test(ua)
        ? "Windows"
        : /Android/.test(ua)
          ? "Android"
          : /iPhone|iPad/.test(ua)
            ? "iOS"
            : /Mac OS/.test(ua)
              ? "macOS"
              : /Linux/.test(ua)
                ? "Linux"
                : "";
    return [browser, os].filter(Boolean).join(" · ");
}

// Hiển thị giá trị trước/sau: ngày giờ ISO -> giờ Việt Nam, true/false -> Có/Không.
// Mã trạng thái -> tiếng Việt (chỉ áp cho các trường trạng thái/ưu tiên).
const ENUM_FIELDS = ["status", "approval_status", "employment_status", "priority", "confirmation_status", "role"];
const VALUE_LABELS = {
    planning: "Lên kế hoạch",
    active: "Đang hoạt động",
    on_hold: "Tạm dừng",
    completed: "Hoàn thành",
    cancelled: "Đã hủy",
    pending: "Chờ duyệt",
    manager_approved: "Quản lý đã duyệt",
    approved: "Đã duyệt",
    rejected: "Từ chối",
    todo: "Cần làm",
    in_progress: "Đang làm",
    in_review: "Chờ duyệt",
    done: "Hoàn thành",
    expired: "Hết hạn",
    terminated: "Đã chấm dứt",
    probation: "Thử việc",
    intern: "Thực tập",
    resigned: "Đã nghỉ việc",
    inactive: "Ngừng hoạt động",
    open: "Đang mở",
    closed: "Đã đóng",
    low: "Thấp",
    medium: "Trung bình",
    high: "Cao",
    urgent: "Khẩn cấp",
    confirmed: "Đã xác nhận",
    lead: "Lead",
    member: "Thành viên",
};

function format(value, field = null) {
    if (value === null || value === undefined || value === "") return "—";
    if (field && ENUM_FIELDS.includes(field) && VALUE_LABELS[value]) return VALUE_LABELS[value];
    if (typeof value === "boolean") return value ? "Có" : "Không";
    if (typeof value === "object") return JSON.stringify(value);
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) {
        const date = new Date(text);
        return date.getHours() === 0 && date.getMinutes() === 0
            ? date.toLocaleDateString("vi-VN")
            : date.toLocaleString("vi-VN");
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return new Date(`${text}T00:00:00`).toLocaleDateString("vi-VN");
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text))
        return new Date(text.replace(" ", "T")).toLocaleString("vi-VN");
    return text;
}

function params() {
    return {
        page: page.value,
        search: search.value || undefined,
        user_id: userId.value || undefined,
        module: module.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        action: actions.value.length ? actions.value : undefined,
    };
}

function load() {
    return store.fetchList(params());
}

async function openDetail(id) {
    detail.value = null;
    detailOpen.value = true;
    try {
        detail.value = await store.fetchDetail(id);
    } catch (e) {
        detailOpen.value = false;
        toast.error(e.response?.data?.message ?? "Không tải được chi tiết nhật ký.");
    }
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 350);
});
watch([userId, module, dateFrom, dateTo, actions], () => (page.value === 1 ? load() : (page.value = 1)), {
    deep: true,
});
watch(page, load);

onMounted(() => {
    load();
    store.fetchFilters();
});
</script>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.filters__search {
    flex: 1 1 240px;
    max-width: 320px;
}
.filters__select {
    flex: 1 1 200px;
    max-width: 260px;
}
.filters__date {
    width: 180px;
}
.day-head {
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
    color: rgb(var(--v-theme-ink-muted));
    background: rgba(var(--v-theme-on-surface), 0.03);
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
}
.log-row {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    transition: background 0.12s ease;
}
.log-row:hover,
.log-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.04);
    outline: none;
}
.log-row__time {
    width: 44px;
    flex-shrink: 0;
    font-size: 12.5px;
    font-variant-numeric: tabular-nums;
    color: rgb(var(--v-theme-ink-muted));
}
.log-row__ip {
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 48px 16px;
    color: rgb(var(--v-theme-ink-muted));
}
.meta-grid {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr);
    gap: 6px 16px;
    font-size: 0.875rem;
}
.meta-label {
    color: rgb(var(--v-theme-ink-muted));
}
.method-tag {
    font-size: 11px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
    background: rgba(var(--v-theme-on-surface), 0.07);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.section-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 8px;
}
.diff-table {
    display: grid;
    grid-template-columns: minmax(120px, 0.8fr) minmax(0, 1fr) minmax(0, 1fr);
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
    overflow: hidden;
    font-size: 0.875rem;
}
.diff-head {
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
    background: rgba(var(--v-theme-on-surface), 0.03);
}
.diff-cell {
    padding: 8px 12px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
    word-break: break-word;
    white-space: pre-line;
}
.diff-cell--label {
    font-weight: 500;
}
.diff-cell--before {
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.04);
    text-decoration: line-through;
    text-decoration-color: rgba(var(--v-theme-error), 0.4);
}
.diff-cell--after {
    color: rgb(var(--v-theme-success));
    background: rgba(var(--v-theme-success), 0.05);
    font-weight: 500;
}
.snapshot {
    display: grid;
    grid-template-columns: minmax(130px, 0.6fr) minmax(0, 1fr);
    gap: 4px 16px;
    font-size: 0.875rem;
    padding: 12px 14px;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
    max-height: 340px;
    overflow-y: auto;
}
.snapshot__label {
    color: rgb(var(--v-theme-ink-muted));
}
.snapshot__value {
    word-break: break-word;
}
.related {
    display: flex;
    flex-direction: column;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
    overflow: hidden;
}
.related__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    text-align: left;
    font-size: 0.875rem;
    min-width: 0;
}
.related__item + .related__item {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.related__item:hover {
    background: rgba(var(--v-theme-primary), 0.05);
}
@media (max-width: 760px) {
    .log-row__ip {
        display: none;
    }
    .filters__search,
    .filters__select {
        max-width: none;
    }
    .filters__date {
        flex: 1 1 140px;
    }
    .diff-table {
        grid-template-columns: minmax(90px, 0.7fr) minmax(0, 1fr) minmax(0, 1fr);
    }
}
</style>
