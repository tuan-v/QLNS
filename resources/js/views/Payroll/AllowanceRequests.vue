<template>
    <div>
        <v-alert type="info" variant="tonal" density="compact" class="mb-4" icon="mdi-information-outline">
            <template v-if="canApprove">
                Duyệt thì phụ cấp áp dụng từ ngày HR đề xuất. Bảng lương đã tạo không tự cập nhật — nếu ngày áp dụng rơi
                vào kỳ lương đã có, hệ thống sẽ nhắc khi duyệt.
            </template>
            <template v-else>
                Thay đổi phụ cấp của nhân viên được gửi tới Admin duyệt và chỉ áp dụng sau khi được duyệt. Gửi lại cho
                cùng nhân viên và khoản sẽ thay đề xuất đang chờ.
            </template>
        </v-alert>

        <div class="filters mb-3">
            <v-select
                v-model="status"
                :items="STATUS_OPTIONS"
                label="Trạng thái"
                density="compact"
                hide-details
                class="filters__item"
            />
            <v-text-field
                v-model="search"
                label="Tìm nhân viên"
                prepend-inner-icon="mdi-magnify"
                density="compact"
                hide-details
                clearable
                class="filters__item"
            />
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>

        <DataTable
            :headers="headers"
            :items="items"
            :loading="loading"
            :actions="actions"
            :selectable="canApprove"
            :item-selectable="(item) => item.can_decide"
            :bulk-actions="bulkActions"
            :items-per-page="25"
            no-data-text="Không có đề xuất nào."
            @action-error="load"
            @bulk-action-error="load"
        >
            <template #item.employee="{ item }">
                <div class="d-flex align-center ga-3 py-1">
                    <PersonAvatar :person="item.employee" :size="32" />
                    <div class="min-w-0">
                        <div class="font-weight-medium">{{ item.employee.full_name }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ item.employee.code
                            }}<template v-if="item.employee.department"> · {{ item.employee.department }}</template>
                        </div>
                    </div>
                </div>
            </template>
            <template #item.change="{ item }">
                <div class="font-weight-medium">{{ item.component.name }}</div>
                <div class="text-body-2">
                    <span class="text-medium-emphasis">{{ previousText(item) }}</span>
                    <v-icon icon="mdi-arrow-right" size="14" class="mx-1" />
                    <span class="font-weight-medium">{{ amountText(item.amount, item.component.unit) }}</span>
                </div>
                <div class="text-caption text-medium-emphasis">Áp dụng từ {{ formatDate(item.effective_from) }}</div>
            </template>
            <template #item.note="{ item }">
                <span v-if="item.note">{{ item.note }}</span>
                <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.requested_by="{ item }">
                <div>{{ item.requested_by ?? "—" }}</div>
                <div class="text-caption text-medium-emphasis">{{ formatDateTime(item.requested_at) }}</div>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="STATUS_MAP" />
                <div v-if="item.decided_by" class="text-caption text-medium-emphasis mt-1">
                    {{ item.decided_by }} · {{ formatDateTime(item.decided_at) }}
                </div>
                <div v-if="item.decision_note" class="text-caption mt-1">{{ item.decision_note }}</div>
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import DataTable from "../../components/common/DataTable.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import PersonAvatar from "../Project/PersonAvatar.vue";
import allowanceService from "../../services/allowanceService";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Tab "Đề xuất" (2026-10-21): HR đổi phụ cấp của nhân viên -> đề xuất ở đây; Admin (allowance.approve) duyệt /
// từ chối từng cái hoặc hàng loạt, người đề xuất rút lại được khi còn chờ. Xem AllowanceChangeService.php.
const props = defineProps({
    active: { type: Boolean, default: false },
    initialStatus: { type: String, default: "pending" },
});
const emit = defineEmits(["pending"]);

const toast = useToastStore();

const STATUS_OPTIONS = [
    { title: "Chờ duyệt", value: "pending" },
    { title: "Đã duyệt", value: "approved" },
    { title: "Từ chối", value: "rejected" },
    { title: "Đã rút lại", value: "cancelled" },
    { title: "Tất cả", value: "all" },
];
const STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "error" },
    cancelled: { label: "Đã rút lại", color: "default" },
};

const headers = [
    { title: "Nhân viên", key: "employee", sortable: false, minWidth: 200 },
    { title: "Thay đổi", key: "change", sortable: false, minWidth: 210 },
    { title: "Nội dung / lý do", key: "note", sortable: false, minWidth: 140 },
    { title: "Người đề xuất", key: "requested_by", sortable: false, width: 140 },
    { title: "Trạng thái", key: "status", sortable: false, width: 150 },
];

const items = ref([]);
const loading = ref(false);
const error = ref("");
const canApprove = ref(false);
const status = ref(props.initialStatus);
const search = ref("");

watch(
    () => props.initialStatus,
    (value) => (status.value = value),
);

const money = (value) => `${Number(value ?? 0).toLocaleString("vi-VN")}đ`;
const amountText = (amount, unit) =>
    Number(amount) > 0 ? `${money(amount)}/${unit === "day" ? "ngày" : "tháng"}` : "Không hưởng";
function previousText(item) {
    if (item.previous_state === "unset") return "Chưa đặt";
    if (item.previous_state === "off") return "Không hưởng";
    const text = amountText(item.previous_amount, item.component.unit);
    return item.previous_state === "default" ? `${text} (mặc định)` : text;
}
const formatDate = (value) => (value ? new Date(value).toLocaleDateString("vi-VN") : "—");
const formatDateTime = (value) =>
    value
        ? new Date(value).toLocaleString("vi-VN", {
              hour: "2-digit",
              minute: "2-digit",
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
          })
        : "";

let searchTimer = null;
async function load() {
    loading.value = true;
    error.value = "";
    try {
        const { data } = await allowanceService.requests({
            status: status.value,
            search: search.value?.trim() || undefined,
            per_page: 100,
        });
        items.value = data.data;
        canApprove.value = data.meta.can_approve;
        emit("pending", data.meta.pending);
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được danh sách đề xuất.";
    } finally {
        loading.value = false;
    }
}
watch(status, load);
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(
    () => props.active,
    (value) => value && load(),
    { immediate: true },
);

// Duyệt / từ chối trả 200 kèm danh sách lỗi (đề xuất vừa được người khác xử lý, khoản đã ngừng...) — báo qua toast.
function report(data, verb) {
    if (!data.failed?.length) {
        toast.success(`Đã ${verb} ${data.done} đề xuất.`);
    } else {
        toast.warning(`${data.done} đề xuất đã ${verb}, ${data.failed.length} lỗi: ${data.failed[0].message}`);
    }
    if (data.payroll_warning) toast.warning(data.payroll_warning);
}
async function decide(ids, approve, note) {
    const { data } = approve
        ? await allowanceService.approveRequests(ids, note || null)
        : await allowanceService.rejectRequests(ids, note);
    report(data, approve ? "duyệt" : "từ chối");
    await load();
}

const describe = (item) =>
    `${item.component.name} cho ${item.employee.full_name}: ${previousText(item)} → ${amountText(item.amount, item.component.unit)}, từ ${formatDate(item.effective_from)}.`;

const actions = computed(() => [
    {
        icon: "mdi-check",
        tooltip: "Duyệt",
        color: "success",
        hidden: (item) => !item.can_decide,
        confirm: {
            title: "Duyệt đề xuất phụ cấp",
            message: describe,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn)" },
        },
        onClick: (item, { input }) => decide([item.id], true, input),
    },
    {
        icon: "mdi-close",
        tooltip: "Từ chối",
        color: "error",
        hidden: (item) => !item.can_decide,
        confirm: {
            title: "Từ chối đề xuất phụ cấp",
            message: describe,
            confirmText: "Từ chối",
            input: { required: true, label: "Lý do từ chối" },
        },
        onClick: (item, { input }) => decide([item.id], false, input),
    },
    {
        icon: "mdi-undo-variant",
        tooltip: "Rút lại đề xuất",
        color: "secondary",
        hidden: (item) => !item.can_cancel || item.can_decide,
        confirm: {
            title: "Rút lại đề xuất",
            message: describe,
            confirmText: "Rút lại",
        },
        onClick: async (item) => {
            await allowanceService.cancelRequest(item.id);
            toast.success("Đã rút lại đề xuất.");
            await load();
        },
    },
]);

const bulkActions = computed(() => [
    {
        icon: "mdi-check-all",
        label: "Duyệt đã chọn",
        tooltip: "Duyệt đã chọn",
        color: "success",
        confirm: {
            title: "Duyệt đề xuất phụ cấp",
            message: (selected) => `Duyệt ${selected.length} đề xuất đã chọn?`,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn, áp dụng cho tất cả)" },
        },
        onClick: (selected, { input }) =>
            decide(
                selected.map((item) => item.id),
                true,
                input,
            ),
    },
    {
        icon: "mdi-close-box-multiple-outline",
        label: "Từ chối đã chọn",
        tooltip: "Từ chối đã chọn",
        color: "error",
        confirm: {
            title: "Từ chối đề xuất phụ cấp",
            message: (selected) => `Từ chối ${selected.length} đề xuất đã chọn?`,
            confirmText: "Từ chối",
            input: { required: true, label: "Lý do từ chối (áp dụng cho tất cả)" },
        },
        onClick: (selected, { input }) =>
            decide(
                selected.map((item) => item.id),
                false,
                input,
            ),
    },
]);

useRealtimeRefresh(() => props.active && load(), {
    shared: [{ resource: "allowance_requests", permission: "payroll.view_all" }],
});
</script>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
.filters__item {
    flex: 1 1 220px;
    max-width: 320px;
}
</style>
