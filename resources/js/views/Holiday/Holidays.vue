<template>
    <div>
        <PageHeader
            title="Ngày nghỉ lễ"
            subtitle="Lịch nghỉ lễ trong năm — tự tính theo dương lịch và âm lịch, tự thông báo cho nhân viên trước mỗi dịp nghỉ."
        >
            <template #actions>
                <v-btn
                    v-if="canManage"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-calendar-refresh-outline"
                    :loading="generating"
                    @click="generate"
                >
                    Tính tự động năm {{ year }}
                </v-btn>
                <v-btn
                    v-if="canManage"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-plus"
                    @click="formDialog = true"
                >
                    Ngày nghỉ
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
        >
            {{ loadError }}
        </v-alert>

        <v-alert
            v-if="canManage && pendingConfirm.length"
            type="warning"
            variant="tonal"
            class="mb-4"
            icon="mdi-calendar-alert"
        >
            <div class="font-weight-bold">
                {{ pendingConfirm.length }} dịp nghỉ sắp tới chưa được xác nhận
            </div>
            <div class="text-body-2">
                Lịch do hệ thống tự tính theo luật. Đối chiếu thông báo chính thức của
                Nhà nước, bấm "Điều chỉnh" nếu cần (thêm/bỏ ngày, hoán đổi ngày làm
                việc) rồi "Xác nhận" — nhân viên chỉ được tự thông báo sau khi xác nhận.
                <span class="font-weight-medium">
                    {{ pendingConfirm.map((g) => g.name).join(", ") }}
                </span>
            </div>
        </v-alert>

        <v-sheet
            v-if="upcoming"
            class="border rounded-lg pa-4 mb-4 glass-panel d-flex align-center ga-3 flex-wrap"
            color="transparent"
        >
            <v-icon icon="mdi-party-popper" color="primary" size="28" />
            <div>
                <div class="text-subtitle-1 font-weight-bold">
                    Sắp tới: {{ upcoming.name }}
                    <span class="text-primary">
                        ({{
                            daysUntil(upcoming.start_date) === 0
                                ? "hôm nay"
                                : `còn ${daysUntil(upcoming.start_date)} ngày`
                        }})
                    </span>
                </div>
                <div class="text-body-2" style="opacity: 0.75">
                    Nghỉ {{ formatRange(upcoming) }} ·
                    {{ upcoming.total_days }} ngày · đi làm lại
                    {{ formatDate(upcoming.return_date) }}
                </div>
            </div>
        </v-sheet>

        <v-sheet
            class="border rounded-lg pa-3 mb-4 glass-panel d-flex align-center ga-2"
            color="transparent"
        >
            <v-btn
                icon="mdi-chevron-left"
                variant="text"
                size="small"
                @click="year--"
            />
            <div
                class="text-subtitle-1 font-weight-bold"
                style="min-width: 90px; text-align: center"
            >
                Năm {{ year }}
            </div>
            <v-btn
                icon="mdi-chevron-right"
                variant="text"
                size="small"
                @click="year++"
            />
            <v-spacer />
            <div class="text-body-2" style="opacity: 0.7">
                {{ groups.length }} dịp nghỉ · {{ totalWeekdays }} ngày làm việc
                được nghỉ
            </div>
        </v-sheet>

        <v-tabs v-model="tab" color="primary" class="mb-3">
            <v-tab value="calendar" prepend-icon="mdi-calendar-month-outline"
                >Lịch nghỉ</v-tab
            >
            <v-tab
                v-if="canManage"
                value="rules"
                prepend-icon="mdi-calendar-sync-outline"
            >
                Ngày nghỉ lễ hằng năm
            </v-tab>
        </v-tabs>

        <v-window v-model="tab">
            <v-window-item value="calendar">
                <DataTable
                    :headers="headers"
                    :items="groups"
                    :loading="loading"
                    :actions="actions"
                    :actions-width="190"
                >
                    <template #item.name="{ item }">
                        <div class="font-weight-medium">{{ item.name }}</div>
                        <div class="d-flex ga-1 flex-wrap mt-1">
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="
                                    item.source === 'rule'
                                        ? 'primary'
                                        : 'secondary'
                                "
                            >
                                {{
                                    item.source === "rule"
                                        ? "Theo quy tắc"
                                        : "Công ty"
                                }}
                            </v-chip>
                            <v-chip size="x-small" variant="tonal" color="info">
                                {{
                                    item.calendar === "lunar"
                                        ? "Âm lịch"
                                        : "Dương lịch"
                                }}
                            </v-chip>
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="item.confirmed ? 'success' : 'warning'"
                            >
                                {{ item.confirmed ? "Đã xác nhận" : "Chưa xác nhận" }}
                            </v-chip>
                            <v-chip
                                v-if="item.adjusted"
                                size="x-small"
                                variant="tonal"
                                color="secondary"
                            >
                                HR đã điều chỉnh
                            </v-chip>
                            <v-chip
                                v-if="!item.is_paid"
                                size="x-small"
                                variant="tonal"
                                color="warning"
                            >
                                Không lương
                            </v-chip>
                            <v-chip
                                v-if="!item.intern_paid"
                                size="x-small"
                                variant="tonal"
                                color="info"
                            >
                                Thực tập sinh không lương
                            </v-chip>
                        </div>
                    </template>
                    <template #item.period="{ item }">
                        <div>{{ formatRange(item) }}</div>
                        <div
                            v-for="day in item.days"
                            :key="day.id"
                            class="text-caption"
                            style="opacity: 0.7"
                        >
                            {{ day.weekday }} {{ formatDate(day.date) }} ·
                            {{ day.lunar_label }}
                            <span
                                v-if="day.source === 'compensatory'"
                                class="text-warning"
                                >· nghỉ bù</span
                            >
                            <span v-if="day.source === 'swap'" class="text-info"
                                >· hoán đổi</span
                            >
                        </div>
                        <div
                            v-for="m in item.makeup_days"
                            :key="m.date"
                            class="text-caption text-info"
                        >
                            Đi làm bù: {{ m.weekday }} {{ formatDate(m.date) }}
                        </div>
                    </template>
                    <template #item.total_days="{ item }">
                        {{ item.total_days }} ngày
                        <div class="text-caption" style="opacity: 0.6">
                            ({{ item.weekday_days }} ngày làm việc)
                        </div>
                    </template>
                    <template #item.return_date="{ item }">
                        {{ formatDate(item.return_date) }}
                    </template>
                    <template #item.notify="{ item }">
                        <v-chip
                            v-if="item.notified_at"
                            size="small"
                            variant="tonal"
                            color="success"
                        >
                            Đã gửi {{ formatDateTime(item.notified_at) }}
                        </v-chip>
                        <span
                            v-else-if="isPast(item.start_date)"
                            style="opacity: 0.5"
                            >—</span
                        >
                        <span
                            v-else-if="!item.confirmed"
                            class="text-body-2 text-warning"
                        >
                            Chờ xác nhận (nhắc HR ngày {{ formatDate(item.remind_on) }})
                        </span>
                        <span v-else class="text-body-2" style="opacity: 0.75">
                            Tự gửi ngày {{ formatDate(item.notify_on) }}
                        </span>
                    </template>
                </DataTable>
            </v-window-item>

            <v-window-item v-if="canManage" value="rules">
                <div class="d-flex mb-3">
                    <v-spacer />
                    <v-btn
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-plus"
                        @click="openRule(null)"
                    >
                        Ngày nghỉ
                    </v-btn>
                </div>
                <DataTable
                    :headers="ruleHeaders"
                    :items="rules"
                    :loading="rulesLoading"
                    :actions="ruleActions"
                >
                    <template #item.name="{ item }">
                        <div class="font-weight-medium">{{ item.name }}</div>
                        <div
                            v-if="item.description"
                            class="text-caption"
                            style="opacity: 0.6"
                        >
                            {{ item.description }}
                        </div>
                    </template>
                    <template #item.base="{ item }">
                        {{ item.day }}/{{ item.month }}
                        {{
                            item.calendar === "lunar" ? "âm lịch" : "dương lịch"
                        }}
                    </template>
                    <template #item.how="{ item }">
                        {{ item.duration_days }} ngày
                        <span v-if="item.offset_days" style="opacity: 0.7">
                            (bắt đầu
                            {{
                                item.offset_days < 0
                                    ? `trước ${-item.offset_days}`
                                    : `sau ${item.offset_days}`
                            }}
                            ngày)
                        </span>
                        <div class="text-caption" style="opacity: 0.6">
                            Báo trước {{ item.notify_days_before }} ngày
                            <span v-if="item.compensate_weekend">
                                · nghỉ bù cuối tuần</span
                            >
                            <span v-if="!item.intern_paid">
                                · thực tập sinh không lương</span
                            >
                            <span v-if="item.effective_from">
                                · áp dụng từ
                                {{ formatDate(item.effective_from) }}</span
                            >
                        </div>
                    </template>
                    <template #item.status="{ item }">
                        <v-chip
                            size="small"
                            variant="tonal"
                            :color="item.is_active ? 'success' : 'default'"
                        >
                            {{ item.is_active ? "Đang áp dụng" : "Đã tắt" }}
                        </v-chip>
                        <v-chip
                            v-if="item.is_system"
                            size="small"
                            variant="tonal"
                            color="primary"
                            class="ml-1"
                        >
                            Theo luật
                        </v-chip>
                    </template>
                </DataTable>
            </v-window-item>
        </v-window>

        <HolidayForm v-model="formDialog" :year="year" @saved="loadData" />
        <HolidayAdjustDialog
            v-model="adjustDialog"
            :group-code="adjustingGroup"
            @changed="loadData"
        />
        <HolidayRuleForm
            v-model="ruleDialog"
            :rule="editingRule"
            @saved="onRuleSaved"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import holidayService from "../../services/holidayService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import HolidayForm from "./HolidayForm.vue";
import HolidayRuleForm from "./HolidayRuleForm.vue";
import HolidayAdjustDialog from "./HolidayAdjustDialog.vue";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { useRouteAction } from "../../composables/useRouteAction";

const auth = useAuthStore();
const toast = useToastStore();
const canManage = computed(() => auth.permissions.includes("holiday.manage"));

const year = ref(new Date().getFullYear());
const tab = ref("calendar");
const groups = ref([]);
const loading = ref(false);
const loadError = ref("");
const generating = ref(false);
const formDialog = ref(false);

const rules = ref([]);
const rulesLoading = ref(false);
const ruleDialog = ref(false);
const editingRule = ref(null);

const headers = [
    { title: "Dịp nghỉ", key: "name" },
    { title: "Thời gian", key: "period" },
    { title: "Số ngày", key: "total_days", width: 140 },
    { title: "Đi làm lại", key: "return_date", width: 120 },
    { title: "Thông báo", key: "notify", width: 190 },
];
const ruleHeaders = [
    { title: "Ngày nghỉ", key: "name" },
    { title: "Ngày gốc", key: "base", width: 170 },
    { title: "Cách nghỉ", key: "how" },
    { title: "Trạng thái", key: "status", width: 210 },
];

const todayIso = () => new Date().toISOString().slice(0, 10);
const isPast = (date) => date < todayIso();
const upcoming = computed(
    () => groups.value.find((g) => g.end_date >= todayIso()) ?? null,
);
const adjustDialog = ref(false);
const adjustingGroup = ref(null);
// Dịp sắp tới (chưa kết thúc) mà chưa xác nhận — cần HR đối chiếu lịch chính thức.
const pendingConfirm = computed(() =>
    groups.value.filter((g) => !g.confirmed && g.end_date >= todayIso()),
);
const totalWeekdays = computed(() =>
    groups.value.reduce((sum, g) => sum + g.weekday_days, 0),
);

function daysUntil(date) {
    const ms = new Date(date) - new Date(todayIso());
    return Math.max(0, Math.round(ms / 86400000));
}
function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = value.slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}
function formatDateTime(value) {
    return value ? `${formatDate(value)} ${value.slice(11, 16)}` : "";
}
function formatRange(group) {
    return group.start_date === group.end_date
        ? formatDate(group.start_date)
        : `${formatDate(group.start_date)} – ${formatDate(group.end_date)}`;
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await holidayService.list(year.value);
        groups.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không tải được lịch nghỉ lễ.";
    } finally {
        loading.value = false;
    }
}

async function loadRules() {
    if (!canManage.value) return;
    rulesLoading.value = true;
    try {
        const response = await holidayService.rules();
        rules.value = response.data.data;
    } finally {
        rulesLoading.value = false;
    }
}

async function generate() {
    generating.value = true;
    try {
        const response = await holidayService.generate(year.value);
        const { created, removed } = response.data;
        toast.success(
            created || removed
                ? `Đã tính lịch nghỉ năm ${year.value}: thêm ${created} ngày${removed ? `, gỡ ${removed} ngày` : ""}.`
                : `Lịch nghỉ năm ${year.value} đã đầy đủ, không có thay đổi.`,
        );
        await loadData();
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không tính được lịch nghỉ.");
    } finally {
        generating.value = false;
    }
}

const actions = computed(() => [
    {
        icon: "mdi-check-decagram-outline",
        tooltip: "Xác nhận lịch",
        color: "success",
        hidden: (item) => !canManage.value || item.confirmed || isPast(item.end_date),
        confirm: {
            title: "Xác nhận lịch nghỉ",
            message: (item) =>
                `Xác nhận lịch nghỉ "${item.name}" (${formatRange(item)}, ${item.total_days} ngày) đã khớp thông báo chính thức?`,
            warning: () =>
                "Sau khi xác nhận, hệ thống tự gửi thông báo cho nhân viên trước ngày nghỉ và không tự tính lại dịp này nữa.",
            confirmText: "Xác nhận",
        },
        onClick: async (item) => {
            await holidayService.confirmGroup(item.group_code, true);
            toast.success(`Đã xác nhận lịch nghỉ "${item.name}".`);
            await loadData();
        },
    },
    {
        icon: "mdi-calendar-edit-outline",
        tooltip: "Điều chỉnh (thêm/bỏ ngày, hoán đổi)",
        color: "primary",
        hidden: (item) => !canManage.value || isPast(item.end_date),
        onClick: (item) => {
            adjustingGroup.value = item.group_code;
            adjustDialog.value = true;
        },
    },
    {
        icon: "mdi-school-outline",
        tooltip: "Đổi lương ngày lễ của thực tập sinh",
        color: "info",
        hidden: (item) => !canManage.value || isPast(item.end_date),
        confirm: {
            title: "Lương ngày lễ của thực tập sinh",
            message: (item) =>
                item.intern_paid
                    ? `Đặt "${item.name}" (${formatRange(item)}) là KHÔNG hưởng lương với thực tập sinh?`
                    : `Đặt "${item.name}" (${formatRange(item)}) là CÓ hưởng lương với thực tập sinh?`,
            warning: () =>
                "Chỉ áp dụng cho nhân viên hợp đồng thực tập; bảng lương đã tạo không tự tính lại.",
            confirmText: "Đồng ý",
        },
        onClick: async (item) => {
            await holidayService.setInternPaid(item.group_code, !item.intern_paid);
            toast.success(
                item.intern_paid
                    ? `Thực tập sinh không hưởng lương dịp "${item.name}".`
                    : `Thực tập sinh được hưởng lương dịp "${item.name}".`,
            );
            await loadData();
        },
    },
    {
        icon: "mdi-bell-ring-outline",
        tooltip: "Gửi thông báo ngay",
        color: "primary",
        hidden: (item) => !canManage.value || isPast(item.end_date),
        confirm: {
            title: "Gửi thông báo nghỉ lễ",
            message: (item) =>
                `Gửi thông báo nghỉ "${item.name}" (${formatRange(item)}) tới toàn bộ nhân viên ngay bây giờ?`,
            warning: (item) =>
                item.notified_at
                    ? "Dịp này đã được thông báo trước đó — nhân viên sẽ nhận thêm một lần."
                    : null,
            confirmText: "Gửi thông báo",
        },
        onClick: async (item) => {
            const response = await holidayService.notifyGroup(item.group_code);
            toast.success(
                `Đã gửi thông báo tới ${response.data.recipients} tài khoản.`,
            );
            await loadData();
        },
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa dịp nghỉ",
        color: "error",
        hidden: () => !canManage.value,
        confirm: {
            title: "Xóa dịp nghỉ",
            message: (item) =>
                `Xóa toàn bộ ${item.total_days} ngày nghỉ "${item.name}" của năm ${year.value}?`,
            warning: (item) =>
                item.source === "rule"
                    ? "Lần tính tự động sau sẽ KHÔNG tạo lại dịp này. Muốn bỏ hẳn hằng năm thì tắt quy tắc ở tab Quy tắc."
                    : null,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await holidayService.removeGroup(item.group_code);
            toast.success(`Đã xóa "${item.name}".`);
            await loadData();
        },
    },
]);

const ruleActions = computed(() => [
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        onClick: (item) => openRule(item),
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa quy tắc",
        color: "error",
        hidden: (item) => item.is_system,
        confirm: {
            title: "Xóa quy tắc",
            message: (item) =>
                `Xóa quy tắc "${item.name}"? Các ngày nghỉ sắp tới của quy tắc này cũng bị gỡ.`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await holidayService.removeRule(item.id);
            toast.success(`Đã xóa quy tắc "${item.name}".`);
            await Promise.all([loadRules(), loadData()]);
        },
    },
]);

function openRule(rule) {
    editingRule.value = rule;
    ruleDialog.value = true;
}

async function onRuleSaved() {
    await Promise.all([loadRules(), loadData()]);
}

watch(year, loadData);

// Realtime: HR xác nhận/điều chỉnh lịch, đổi quy tắc... -> mọi người đang xem tự cập nhật.
useRealtimeRefresh(() => Promise.all([loadData(), loadRules()]), { shared: ["holidays"] });

// Mở thẳng thao tác khi vào trang bằng ?action=... (lệnh Ctrl+K).
useRouteAction({ create: () => canManage.value && (formDialog.value = true) });

onMounted(() => {
    loadData();
    loadRules();
});
</script>
