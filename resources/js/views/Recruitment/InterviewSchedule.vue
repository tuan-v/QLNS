<template>
    <div>
        <PageHeader
            title="Lịch phỏng vấn"
            subtitle="Các buổi phỏng vấn trong tháng và phản hồi của ứng viên — HR ghi nhận, hệ thống báo Admin."
        />

        <!-- Chọn tháng + chế độ xem + lọc -->
        <v-sheet class="border rounded-lg pa-3 mb-4 glass-panel" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3">
                <div class="d-flex align-center ga-1">
                    <v-btn
                        icon="mdi-chevron-left"
                        variant="text"
                        size="small"
                        aria-label="Tháng trước"
                        @click="shiftMonth(-1)"
                    />
                    <div class="text-subtitle-1 font-weight-bold text-center" style="min-width: 130px">
                        Tháng {{ monthLabel }}
                    </div>
                    <v-btn
                        icon="mdi-chevron-right"
                        variant="text"
                        size="small"
                        aria-label="Tháng sau"
                        @click="shiftMonth(1)"
                    />
                    <v-btn v-if="month !== currentMonth" variant="text" size="small" @click="month = currentMonth"
                        >Tháng này</v-btn
                    >
                </div>
                <v-btn-toggle v-model="view" mandatory density="compact" variant="outlined" divided color="primary">
                    <v-btn value="calendar" prepend-icon="mdi-calendar-month-outline">Lịch</v-btn>
                    <v-btn value="list" prepend-icon="mdi-format-list-bulleted">Danh sách</v-btn>
                </v-btn-toggle>
                <v-spacer />
                <v-select
                    v-model="openingId"
                    :items="openingOptions"
                    density="compact"
                    hide-details
                    clearable
                    placeholder="Tất cả đợt tuyển"
                    style="max-width: 260px; min-width: 200px"
                />
            </div>
        </v-sheet>

        <!-- Số liệu tháng (bấm để lọc) -->
        <div class="stat-grid mb-4">
            <v-card
                v-for="stat in stats"
                :key="stat.key"
                :class="['stat-card glass-panel', { 'stat-card--active': confirmationFilter === stat.filter }]"
                variant="outlined"
                rounded="lg"
                @click="toggleFilter(stat.filter)"
            >
                <v-card-text class="d-flex align-center ga-3">
                    <v-avatar :color="stat.color" variant="tonal" rounded="lg">
                        <v-icon :icon="stat.icon" />
                    </v-avatar>
                    <div>
                        <div class="text-caption" style="opacity: 0.7">{{ stat.label }}</div>
                        <div class="text-h6 font-weight-bold">{{ summary[stat.key] ?? 0 }}</div>
                    </div>
                </v-card-text>
            </v-card>
        </div>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>
        <div v-if="confirmationFilter" class="mb-3">
            <v-chip closable color="primary" variant="tonal" @click:close="confirmationFilter = null">
                Đang lọc: {{ INTERVIEW_CONFIRMATION_MAP[confirmationFilter]?.label }}
            </v-chip>
        </div>

        <!-- Lịch tháng -->
        <v-sheet v-if="view === 'calendar'" class="border rounded-lg glass-panel calendar" color="transparent">
            <div class="calendar-head">
                <div v-for="d in WEEKDAYS" :key="d" class="calendar-head-cell">{{ d }}</div>
            </div>
            <div class="calendar-body">
                <div
                    v-for="cell in calendarCells"
                    :key="cell.key"
                    :class="[
                        'calendar-cell',
                        { 'calendar-cell--out': !cell.inMonth, 'calendar-cell--today': cell.isToday },
                    ]"
                >
                    <div class="calendar-day">{{ cell.day }}</div>
                    <button
                        v-for="item in cell.items.slice(0, 3)"
                        :key="item.id"
                        type="button"
                        :class="[
                            'calendar-event',
                            `calendar-event--${eventTone(item)}`,
                            { 'calendar-event--done': item.status === 'cancelled' },
                        ]"
                        :title="`${formatTime(item.scheduled_at)} ${item.candidate?.full_name} — ${statusLabel(item)}`"
                        @click="openDetail(item)"
                    >
                        <strong>{{ formatTime(item.scheduled_at) }}</strong> {{ item.candidate?.full_name }}
                    </button>
                    <button v-if="cell.items.length > 3" type="button" class="calendar-more" @click="showDay(cell)">
                        +{{ cell.items.length - 3 }} buổi khác
                    </button>
                </div>
            </div>
            <div class="d-flex flex-wrap ga-3 pa-3 text-caption">
                <span v-for="(meta, key) in INTERVIEW_CONFIRMATION_MAP" :key="key" class="d-flex align-center ga-1">
                    <span :class="['legend-dot', `calendar-event--${key}`]" />{{ meta.label }}
                </span>
                <span class="d-flex align-center ga-1"
                    ><span class="legend-dot calendar-event--completed" />Đã phỏng vấn</span
                >
            </div>
        </v-sheet>

        <!-- Danh sách -->
        <DataTable
            v-else
            :headers="headers"
            :items="interviews"
            :loading="loading"
            :actions="actions"
            no-data-text="Không có buổi phỏng vấn nào trong tháng."
        >
            <template #item.scheduled_at="{ item }">
                <div class="font-weight-medium">{{ formatTime(item.scheduled_at) }}</div>
                <div class="text-caption" style="opacity: 0.7">{{ formatDate(item.scheduled_at) }}</div>
            </template>
            <template #item.candidate="{ item }">
                <div class="font-weight-medium">{{ item.candidate?.full_name }}</div>
                <div class="text-caption" style="opacity: 0.7">
                    {{ item.candidate?.email
                    }}<template v-if="item.candidate?.phone"> · {{ item.candidate.phone }}</template>
                </div>
            </template>
            <template #item.opening="{ item }">
                <router-link
                    :to="{ name: 'recruitment-detail', params: { id: item.opening?.id } }"
                    class="text-primary"
                >
                    {{ item.opening?.title }}
                </router-link>
                <div class="text-caption" style="opacity: 0.7">
                    {{ item.format === "online" ? "Trực tuyến" : "Trực tiếp" }}
                </div>
            </template>
            <template #item.confirmation_status="{ item }">
                <span v-if="item.status === 'completed' && item.confirmation_status === 'pending'" style="opacity: 0.6"
                    >Đã phỏng vấn</span
                >
                <StatusChip v-else :status="item.confirmation_status" :map="INTERVIEW_CONFIRMATION_MAP" />
                <div v-if="item.confirmed_by" class="text-caption" style="opacity: 0.7">HR {{ item.confirmed_by }}</div>
                <div v-if="item.confirmation_note" class="text-caption">{{ item.confirmation_note }}</div>
            </template>
            <template #item.result="{ item }">
                <v-chip
                    v-if="item.status === 'completed'"
                    :color="item.result === 'passed' ? 'success' : 'default'"
                    size="small"
                    variant="tonal"
                >
                    {{ item.result === "passed" ? "Đạt" : "Không đạt" }}
                </v-chip>
                <div v-if="item.scorecard?.score_percent != null" class="text-caption">
                    {{ item.scorecard.score_percent }}%
                </div>
                <v-chip v-else-if="item.scorecard?.status === 'draft'" size="x-small" variant="outlined"
                    >Đang chấm</v-chip
                >
                <span v-else-if="item.status === 'cancelled'" style="opacity: 0.6">Đã hủy</span>
                <span v-else style="opacity: 0.5">—</span>
            </template>
        </DataTable>

        <InterviewDetailDialog
            v-model="detailOpen"
            :interview="selected"
            :can-manage="canManage"
            @saved="onSaved"
            @reschedule="openReschedule"
        />
        <InterviewScheduleDialog v-model="scheduleOpen" :candidate="rescheduleCandidate" @saved="onRescheduled" />

        <!-- Các buổi của 1 ngày (khi ngày có > 3 buổi) -->
        <v-dialog v-model="dayOpen" max-width="420">
            <v-card v-if="dayCell" rounded="xl">
                <v-card-title class="pt-5 px-5">Ngày {{ formatDate(dayCell.date) }}</v-card-title>
                <v-list density="compact">
                    <v-list-item
                        v-for="item in dayCell.items"
                        :key="item.id"
                        @click="
                            dayOpen = false;
                            openDetail(item);
                        "
                    >
                        <v-list-item-title
                            >{{ formatTime(item.scheduled_at) }} · {{ item.candidate?.full_name }}</v-list-item-title
                        >
                        <template #append>
                            <StatusChip :status="item.confirmation_status" :map="INTERVIEW_CONFIRMATION_MAP" />
                        </template>
                    </v-list-item>
                </v-list>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import recruitmentService from "../../services/recruitmentService";
import { useAuthStore } from "../../stores/authStore";
import PageHeader from "../../components/common/PageHeader.vue";
import DataTable from "../../components/common/DataTable.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import InterviewDetailDialog from "./InterviewDetailDialog.vue";
import InterviewScheduleDialog from "./InterviewScheduleDialog.vue";
import { INTERVIEW_CONFIRMATION_MAP } from "../../composables/recruitmentStatus";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const canManage = computed(() => auth.permissions.includes("recruitment.manage"));

const pad = (n) => String(n).padStart(2, "0");
const now = new Date();
const currentMonth = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
const WEEKDAYS = ["T2", "T3", "T4", "T5", "T6", "T7", "CN"];

const month = ref(/^\d{4}-\d{2}$/.test(route.query.month ?? "") ? route.query.month : currentMonth);
const view = useRememberedRef("interview-schedule.view", "calendar");
const openingId = ref(null);
const confirmationFilter = ref(null);
const interviews = ref([]);
const summary = ref({});
const openings = ref([]);
const loading = ref(false);
const loadError = ref("");
const selected = ref(null);
const detailOpen = ref(false);
const scheduleOpen = ref(false);
const rescheduleCandidate = ref(null);
const dayOpen = ref(false);
const dayCell = ref(null);

const monthLabel = computed(() => {
    const [y, m] = month.value.split("-");
    return `${m}/${y}`;
});

const stats = [
    { key: "total", filter: null, label: "Tổng buổi", icon: "mdi-calendar-account-outline", color: "primary" },
    { key: "confirmed", filter: "confirmed", label: "Đồng ý", icon: "mdi-check-circle-outline", color: "success" },
    { key: "pending", filter: "pending", label: "Chờ xác nhận", icon: "mdi-timer-sand", color: "warning" },
    {
        key: "reschedule_requested",
        filter: "reschedule_requested",
        label: "Xin đổi lịch",
        icon: "mdi-calendar-sync-outline",
        color: "info",
    },
    {
        key: "withdrawn",
        filter: "withdrawn",
        label: "Không tham gia",
        icon: "mdi-close-circle-outline",
        color: "error",
    },
];

const headers = [
    { title: "Giờ", key: "scheduled_at", width: 120 },
    { title: "Ứng viên", key: "candidate", sortable: false },
    { title: "Đợt tuyển", key: "opening", sortable: false },
    { title: "Phản hồi", key: "confirmation_status", width: 200 },
    { title: "Kết quả", key: "result", sortable: false, width: 110 },
];

const actions = [{ icon: "mdi-eye-outline", tooltip: "Xem & ghi nhận phản hồi", onClick: (item) => openDetail(item) }];

const openingOptions = computed(() => openings.value.map((o) => ({ title: o.title, value: o.id })));

function formatTime(value) {
    const d = new Date(value);
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function formatDate(value) {
    const d = new Date(value);
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

// Buổi đã phỏng vấn xong (có kết quả) tô màu riêng, không coi là "chờ xác nhận".
function eventTone(item) {
    return item.status === "completed" ? "completed" : item.confirmation_status;
}

function statusLabel(item) {
    return item.status === "completed" ? "Đã phỏng vấn" : INTERVIEW_CONFIRMATION_MAP[item.confirmation_status]?.label;
}

function dayKey(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// Lưới lịch từ thứ Hai đầu tuần chứa ngày 1 tới Chủ nhật cuối tuần chứa ngày cuối tháng.
const calendarCells = computed(() => {
    const [y, m] = month.value.split("-").map(Number);
    const first = new Date(y, m - 1, 1);
    const last = new Date(y, m, 0);
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    const end = new Date(last);
    end.setDate(last.getDate() + (6 - ((last.getDay() + 6) % 7)));

    const byDay = {};
    for (const item of interviews.value) {
        (byDay[dayKey(new Date(item.scheduled_at))] ??= []).push(item);
    }

    const todayKey = dayKey(new Date());
    const cells = [];
    for (const d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
        const key = dayKey(d);
        cells.push({
            key,
            date: new Date(d),
            day: d.getDate(),
            inMonth: d.getMonth() === m - 1,
            isToday: key === todayKey,
            items: byDay[key] ?? [],
        });
    }
    return cells;
});

async function load() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await recruitmentService.listInterviews({
            month: month.value,
            opening_id: openingId.value || undefined,
            confirmation_status: confirmationFilter.value || undefined,
        });
        interviews.value = response.data.data;
        summary.value = response.data.summary;
        // Đang mở chi tiết thì cập nhật theo dữ liệu mới (realtime / vừa lưu).
        if (selected.value) {
            selected.value = interviews.value.find((i) => i.id === selected.value.id) ?? selected.value;
        }
    } catch (e) {
        interviews.value = [];
        loadError.value = e.response?.data?.message ?? "Không tải được lịch phỏng vấn.";
    } finally {
        loading.value = false;
    }
}

async function loadOpenings() {
    try {
        openings.value = (await recruitmentService.listOpenings({ per_page: 100 })).data.data;
    } catch {
        openings.value = [];
    }
}

function shiftMonth(step) {
    const [y, m] = month.value.split("-").map(Number);
    const d = new Date(y, m - 1 + step, 1);
    month.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;
}

function toggleFilter(filter) {
    confirmationFilter.value = confirmationFilter.value === filter ? null : filter;
}

function openDetail(item) {
    selected.value = item;
    detailOpen.value = true;
}

function showDay(cell) {
    dayCell.value = cell;
    dayOpen.value = true;
}

function onSaved(updated) {
    selected.value = updated;
    load();
}

// Dời lịch dùng lại InterviewScheduleDialog (cần dạng "ứng viên + danh sách lịch").
function openReschedule(interview) {
    detailOpen.value = false;
    rescheduleCandidate.value = { ...interview.candidate, interviews: [interview] };
    scheduleOpen.value = true;
}

function onRescheduled() {
    load();
}

// Mở thẳng 1 buổi từ thông báo (?month=YYYY-MM&interview=ID).
async function openFromQuery() {
    const id = Number(route.query.interview);
    if (!id) return;
    const found = interviews.value.find((i) => i.id === id);
    if (found) openDetail(found);
    router.replace({ query: { ...route.query, interview: undefined } });
}

watch([month, openingId, confirmationFilter], load);
watch(
    () => route.query.month,
    async (value) => {
        if (/^\d{4}-\d{2}$/.test(value ?? "") && value !== month.value) {
            month.value = value;
        }
    },
);
watch(
    () => route.query.interview,
    async () => {
        await load();
        openFromQuery();
    },
);

useRealtimeRefresh(load, {
    shared: [{ resource: "recruitment", permission: ["recruitment.manage", "recruitment.approve"] }],
});

onMounted(async () => {
    loadOpenings();
    await load();
    openFromQuery();
});
</script>

<style scoped>
.stat-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}
.stat-card {
    cursor: pointer;
    transition: border-color 0.15s;
}
.stat-card--active {
    border-color: rgb(var(--v-theme-primary)) !important;
    border-width: 2px;
}
.calendar-head,
.calendar-body {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}
.calendar-head-cell {
    padding: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    text-align: center;
    opacity: 0.7;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
}
.calendar-cell {
    min-height: 110px;
    padding: 6px;
    border-right: 1px solid rgb(var(--v-theme-hairline));
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.calendar-cell:nth-child(7n) {
    border-right: 0;
}
.calendar-cell--out {
    opacity: 0.45;
}
.calendar-cell--today .calendar-day {
    background: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-on-primary));
}
.calendar-day {
    align-self: flex-start;
    font-size: 0.75rem;
    font-weight: 700;
    min-width: 22px;
    height: 22px;
    line-height: 22px;
    text-align: center;
    border-radius: 999px;
}
.calendar-event {
    text-align: left;
    font-size: 0.72rem;
    padding: 2px 6px;
    border-radius: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    border-left: 3px solid currentColor;
    cursor: pointer;
}
.calendar-event--done {
    opacity: 0.6;
    text-decoration: line-through;
}
.calendar-event--pending {
    color: rgb(var(--v-theme-warning));
    background: rgba(var(--v-theme-warning), 0.12);
}
.calendar-event--confirmed {
    color: rgb(var(--v-theme-success));
    background: rgba(var(--v-theme-success), 0.12);
}
.calendar-event--reschedule_requested {
    color: rgb(var(--v-theme-info));
    background: rgba(var(--v-theme-info), 0.12);
}
.calendar-event--completed {
    color: rgb(var(--v-theme-on-surface));
    background: rgba(var(--v-theme-on-surface), 0.06);
    opacity: 0.7;
}
.calendar-event--withdrawn {
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.12);
}
.calendar-more {
    font-size: 0.7rem;
    text-align: left;
    opacity: 0.75;
    padding: 0 6px;
}
.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    border-left: 3px solid currentColor;
    display: inline-block;
}
@media (max-width: 960px) {
    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .calendar-cell {
        min-height: 72px;
        padding: 3px;
    }
    .calendar-event {
        font-size: 0.62rem;
        padding: 1px 3px;
    }
}
</style>
