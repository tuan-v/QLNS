<template>
    <!-- pt-2: v-window (khung tab) ẩn phần tràn, thiếu khoảng này thì nhãn nổi của ô chọn bị cắt nửa trên. -->
    <div class="pt-2">
        <!-- Bộ lọc -->
        <div class="filters mb-3">
            <SearchField v-model="search" placeholder="Tìm tên, mã nhân viên" class="filters__item" />
            <v-select
                v-model="departmentId"
                :items="departments"
                :item-props="(item) => ({ style: { paddingInlineStart: `${16 + item.depth * 16}px` } })"
                label="Phòng ban"
                density="compact"
                hide-details
                clearable
                class="filters__item"
            />
            <v-select
                v-model="contractType"
                :items="CONTRACT_OPTIONS"
                label="Loại hợp đồng"
                density="compact"
                hide-details
                clearable
                class="filters__item"
            />
            <v-checkbox v-model="onlyDiffers" label="Chỉ người có khoản khác gợi ý" density="compact" hide-details />
        </div>
        <p class="text-body-2 text-medium-emphasis mb-3">
            {{ meta.total }} nhân viên đang làm việc<template v-if="meta.differs_employees">
                · {{ meta.differs_employees }} người có khoản khác mức gợi ý</template
            >.
            <template v-if="canManage"> Bấm vào một ô để bật/tắt khoản đó hoặc đổi số tiền.</template>
        </p>

        <!-- Thao tác hàng loạt -->
        <div v-if="selected.length" class="bulk-bar mb-3">
            <span class="font-weight-medium">Đã chọn {{ selected.length }} người</span>
            <v-select
                v-model="bulk.componentId"
                :items="components.map((c) => ({ title: c.name, value: c.id }))"
                label="Khoản"
                density="compact"
                hide-details
                class="bulk-bar__field"
            />
            <v-select
                v-model="bulk.mode"
                :items="BULK_MODES"
                label="Thao tác"
                density="compact"
                hide-details
                class="bulk-bar__field"
            />
            <v-btn color="primary" variant="flat" :disabled="!bulk.componentId" @click="openBulk">Tiếp tục</v-btn>
            <v-btn variant="text" @click="selected = []">Bỏ chọn</v-btn>
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
        <v-progress-linear v-if="loading && !rows.length" indeterminate color="primary" class="mb-3" />

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <div class="table-wrap">
                <table class="matrix">
                    <thead>
                        <tr>
                            <th v-if="canManage" class="col-check">
                                <v-checkbox-btn
                                    :model-value="allSelected"
                                    :indeterminate="selected.length > 0 && !allSelected"
                                    density="compact"
                                    aria-label="Chọn tất cả trên trang"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th class="col-employee">Nhân viên</th>
                            <th v-for="c in components" :key="c.id" class="col-cell">
                                <div>{{ c.name }}</div>
                                <div class="th-sub">{{ c.method_label }}</div>
                            </th>
                            <th
                                class="col-total"
                                title="Từ đầu tháng tới hôm nay: khoản theo ngày × số ngày làm tại công ty đã duyệt, cộng khoản theo tháng. Tự cập nhật khi có chấm công. Số chính thức do bảng lương tính."
                            >
                                <div>Ước tính tháng này</div>
                                <div class="th-sub">{{ monthLabel }}, tới hôm nay</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.employee.id">
                            <td v-if="canManage" class="col-check">
                                <v-checkbox-btn
                                    :model-value="selected.includes(row.employee.id)"
                                    density="compact"
                                    :aria-label="`Chọn ${row.employee.full_name}`"
                                    @update:model-value="toggleOne(row.employee.id)"
                                />
                            </td>
                            <td class="col-employee">
                                <div class="d-flex align-center ga-3">
                                    <PersonAvatar :person="row.employee" :size="32" />
                                    <div class="min-w-0">
                                        <router-link
                                            :to="{
                                                name: 'employee-detail',
                                                params: { id: row.employee.id },
                                                query: { tab: 'allowances' },
                                            }"
                                            class="employee-link"
                                        >
                                            {{ row.employee.full_name }}
                                        </router-link>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ row.employee.code }} · {{ row.profile.label.replace("Hợp đồng ", "") }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td v-for="c in components" :key="c.id" class="col-cell">
                                <component
                                    :is="canManage ? 'button' : 'div'"
                                    type="button"
                                    class="cell"
                                    :class="[`cell--${row.cells[c.id].state}`, { 'cell--clickable': canManage }]"
                                    :title="cellTitle(row.cells[c.id])"
                                    @click="canManage && openCell(row, c)"
                                >
                                    <template v-if="['on', 'default'].includes(row.cells[c.id].state)">
                                        <span class="cell__amount">{{
                                            amountText(row.cells[c.id].amount, row.cells[c.id].unit)
                                        }}</span>
                                        <span v-if="row.cells[c.id].state === 'default'" class="cell__sub"
                                            >mặc định</span
                                        >
                                    </template>
                                    <template v-else-if="row.cells[c.id].state === 'off'">Không hưởng</template>
                                    <template v-else>Chưa đặt</template>
                                    <span
                                        v-if="row.cells[c.id].differs && row.cells[c.id].suggested"
                                        class="cell__hint"
                                    >
                                        gợi ý {{ amountText(row.cells[c.id].suggested, row.cells[c.id].unit) }}
                                    </span>
                                    <span v-if="row.cells[c.id].pending" class="cell__pending">
                                        <v-icon icon="mdi-clock-outline" size="12" />
                                        chờ duyệt: {{ pendingText(row.cells[c.id]) }}
                                    </span>
                                </component>
                            </td>
                            <td class="col-total">
                                <div>{{ money(row.estimate.amount) || "0đ" }}</div>
                                <div v-if="hasDaily(row)" class="total__sub">
                                    {{ row.estimate.office_days }} ngày tại công ty
                                </div>
                                <div
                                    v-if="hasDaily(row) && row.estimate.pending_days"
                                    class="total__sub text-warning"
                                    :title="`Nếu HR duyệt thì thêm khoảng ${money(row.estimate.pending_amount)}`"
                                >
                                    +{{ row.estimate.pending_days }} ngày chờ duyệt
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!loading && !rows.length">
                            <td :colspan="components.length + (canManage ? 3 : 2)" class="empty">
                                Không có nhân viên nào khớp bộ lọc.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </v-sheet>

        <div v-if="pageCount > 1" class="d-flex justify-center mt-3">
            <v-pagination v-model="page" :length="pageCount" density="comfortable" total-visible="7" />
        </div>

        <!-- Sửa 1 ô -->
        <v-dialog v-model="cellOpen" max-width="480">
            <v-card v-if="cellCtx" rounded="xl">
                <v-card-title class="pt-5 px-5">{{ cellCtx.component.name }}</v-card-title>
                <v-card-subtitle class="px-5">
                    {{ cellCtx.row.employee.full_name }} · gợi ý
                    {{ amountText(cellCtx.cell.suggested, cellCtx.cell.unit) }} theo
                    {{ cellCtx.row.profile.label.toLowerCase() }}
                </v-card-subtitle>
                <v-card-text class="pt-4">
                    <v-alert
                        v-if="cellCtx.cell.pending"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-clock-outline"
                    >
                        Đang chờ Admin duyệt: {{ pendingText(cellCtx.cell) }} từ
                        {{ formatDate(cellCtx.cell.pending.effective_from) }} ({{ cellCtx.cell.pending.requested_by }}).
                        Lưu lần nữa sẽ thay đề xuất này.
                    </v-alert>
                    <v-alert
                        v-else-if="!canApprove"
                        type="info"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-information-outline"
                    >
                        Thay đổi sẽ được gửi tới Admin duyệt, chỉ áp dụng sau khi được duyệt.
                    </v-alert>
                    <v-btn-toggle
                        v-model="cellForm.entitled"
                        mandatory
                        divided
                        variant="outlined"
                        color="primary"
                        class="mb-4 w-100"
                    >
                        <v-btn :value="true" class="flex-grow-1">Được hưởng</v-btn>
                        <v-btn :value="false" class="flex-grow-1">Không hưởng</v-btn>
                    </v-btn-toggle>
                    <template v-if="cellForm.entitled">
                        <InputMoney
                            v-model="cellForm.amount"
                            :label="
                                cellCtx.cell.unit === 'day' ? 'Số tiền mỗi ngày làm tại công ty' : 'Số tiền mỗi tháng'
                            "
                            suffix="đ"
                            :error-messages="cellErrors.amount"
                        />
                        <v-btn
                            v-if="cellCtx.cell.suggested && cellForm.amount !== cellCtx.cell.suggested"
                            size="x-small"
                            variant="tonal"
                            class="mb-3"
                            @click="cellForm.amount = cellCtx.cell.suggested"
                        >
                            Dùng mức gợi ý {{ amountText(cellCtx.cell.suggested, cellCtx.cell.unit) }}
                        </v-btn>
                    </template>
                    <InputDate
                        v-model="cellForm.effective_from"
                        label="Áp dụng từ ngày"
                        :error-messages="cellErrors.effective_from"
                    />
                    <v-textarea
                        v-model="cellForm.note"
                        :label="
                            cellNeedsNote ? 'Nội dung / lý do (bắt buộc vì khác mức gợi ý)' : 'Ghi chú (không bắt buộc)'
                        "
                        rows="2"
                        auto-grow
                        variant="outlined"
                        density="comfortable"
                        class="mt-2"
                        :error-messages="cellErrors.note"
                    />
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="cellOpen = false">Hủy</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="saving"
                        :disabled="
                            !cellForm.effective_from ||
                            (cellForm.entitled && !cellForm.amount) ||
                            (cellNeedsNote && !cellForm.note.trim())
                        "
                        @click="saveCell"
                    >
                        {{ canApprove ? "Lưu" : "Gửi duyệt" }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xác nhận thao tác hàng loạt -->
        <v-dialog v-model="bulkOpen" max-width="480">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">{{ bulkTitle }}</v-card-title>
                <v-card-text>
                    <p class="mb-4">{{ bulkDescription }}</p>
                    <InputMoney
                        v-if="bulk.mode === 'amount'"
                        v-model="bulk.amount"
                        :label="bulkComponent?.unit === 'day' ? 'Số tiền mỗi ngày' : 'Số tiền mỗi tháng'"
                        suffix="đ"
                    />
                    <InputDate v-model="bulk.effective_from" label="Áp dụng từ ngày" />
                    <v-textarea
                        v-if="bulk.mode !== 'suggested'"
                        v-model="bulk.note"
                        label="Nội dung / lý do (bắt buộc khi khác mức gợi ý)"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        density="comfortable"
                        class="mt-2"
                    />
                    <v-alert v-if="bulkFailed.length" type="warning" variant="tonal" density="compact" class="mt-2">
                        <div v-for="f in bulkFailed" :key="f.id">{{ f.full_name }}: {{ f.message }}</div>
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="bulkOpen = false">Đóng</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="saving"
                        :disabled="!bulk.effective_from || (bulk.mode === 'amount' && !bulk.amount)"
                        @click="runBulk"
                    >
                        {{ canApprove ? "Áp dụng" : "Gửi duyệt" }} cho {{ selected.length }} người
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import SearchField from "../../components/common/SearchField.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import InputDate from "../../components/common/InputDate.vue";
import PersonAvatar from "../Project/PersonAvatar.vue";
import allowanceService from "../../services/allowanceService";
import departmentService from "../../services/departmentService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Tab "Theo nhân viên" của trang Phụ cấp & trợ cấp: mỗi nhân viên đang làm việc 1 dòng, mỗi khoản 1 cột.
// HR bấm ô để bật/tắt (được hưởng / không hưởng) và đặt số tiền; chọn nhiều dòng để đặt hàng loạt.
// Khác mức gợi ý thì bắt buộc lý do (AllowanceService::set()).
const props = defineProps({
    active: { type: Boolean, default: true },
});
const auth = useAuthStore();
const toast = useToastStore();
const canManage = computed(() => auth.permissions.includes("payroll.manage"));
// Không có quyền duyệt (HR) -> mọi thay đổi thành đề xuất chờ Admin duyệt (AllowanceChangeService.php).
const canApprove = computed(() => auth.permissions.includes("allowance.approve"));

const CONTRACT_OPTIONS = [
    { title: "Chính thức", value: "chinh_thuc" },
    { title: "Thử việc", value: "thu_viec" },
    { title: "Thực tập", value: "thuc_tap" },
];
const BULK_MODES = [
    { title: "Hưởng theo mức gợi ý", value: "suggested" },
    { title: "Hưởng cùng một mức", value: "amount" },
    { title: "Không hưởng", value: "none" },
];

const search = ref("");
const departmentId = ref(null);
const contractType = ref(null);
const onlyDiffers = ref(false);
const page = ref(1);
const perPage = 25;
const departments = ref([]);

const components = ref([]);
const rows = ref([]);
const meta = ref({ total: 0, differs_employees: 0 });
const loading = ref(false);
const error = ref("");
const saving = ref(false);
const selected = ref([]);

const pageCount = computed(() => Math.ceil(meta.value.total / perPage));
const allSelected = computed(
    () => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.employee.id)),
);

const money = (value) => (value ? `${Number(value).toLocaleString("vi-VN")}đ` : "");
// "30.000đ/ngày" cho khoản tính theo ngày làm tại công ty, "500.000đ" cho khoản theo tháng.
const amountText = (value, unit) => `${money(value) || "0đ"}${unit === "day" ? "/ngày" : ""}`;
const monthLabel = `Tháng ${new Date().getMonth() + 1}/${new Date().getFullYear()}`;
// Có khoản tính theo ngày đang hưởng (ăn trưa) thì mới hiện số ngày tại công ty dưới cột ước tính.
const hasDaily = (row) =>
    components.value.some((c) => row.cells[c.id]?.unit === "day" && ["on", "default"].includes(row.cells[c.id]?.state));
const firstOfMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
};

const pendingText = (cell) => (cell.pending.amount > 0 ? amountText(cell.pending.amount, cell.unit) : "không hưởng");
const formatDate = (value) => (value ? new Date(value).toLocaleDateString("vi-VN") : "");

function cellTitle(cell) {
    const parts = [`Gợi ý: ${amountText(cell.suggested, cell.unit)}`];
    if (cell.state === "default") parts.push("Mặc định cho mọi nhân viên");
    if (cell.effective_from) parts.push(`Từ ${new Date(cell.effective_from).toLocaleDateString("vi-VN")}`);
    if (cell.note) parts.push(`Lý do: ${cell.note}`);
    if (cell.pending) parts.push(`Chờ duyệt: ${pendingText(cell)} từ ${formatDate(cell.pending.effective_from)}`);
    return parts.join(" · ");
}

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = "";
    try {
        const { data } = await allowanceService.employees({
            search: search.value?.trim() || undefined,
            department_id: departmentId.value || undefined,
            contract_type: contractType.value || undefined,
            only_differs: onlyDiffers.value ? 1 : undefined,
            page: page.value,
            per_page: perPage,
        });
        components.value = data.components;
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được danh sách phụ cấp.";
    } finally {
        loading.value = false;
    }
}

function toggleOne(id) {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}
function toggleAll(value) {
    const ids = rows.value.map((r) => r.employee.id);
    selected.value = value
        ? [...new Set([...selected.value, ...ids])]
        : selected.value.filter((id) => !ids.includes(id));
}

// ---- sửa 1 ô
const cellOpen = ref(false);
const cellCtx = ref(null);
const cellForm = reactive({ entitled: true, amount: null, effective_from: "", note: "" });
const cellErrors = ref({});
const cellNeedsNote = computed(() => {
    if (!cellCtx.value) return false;
    const value = cellForm.entitled ? (cellForm.amount ?? 0) : 0;
    return Math.round(value) !== Math.round(cellCtx.value.cell.suggested);
});

function openCell(row, component) {
    const cell = row.cells[component.id];
    cellCtx.value = { row, component, cell };
    cellErrors.value = {};
    Object.assign(
        cellForm,
        cell.pending
            ? {
                  entitled: cell.pending.amount > 0,
                  amount: cell.pending.amount > 0 ? cell.pending.amount : cell.suggested || null,
                  effective_from: cell.pending.effective_from,
                  note: cell.pending.note ?? "",
              }
            : {
                  entitled: cell.state !== "off" && (["on", "default"].includes(cell.state) || cell.suggested > 0),
                  amount: ["on", "default"].includes(cell.state) ? cell.amount : cell.suggested || null,
                  effective_from: firstOfMonth(),
                  note: "",
              },
    );
    cellOpen.value = true;
}

async function saveCell() {
    saving.value = true;
    cellErrors.value = {};
    try {
        const { data } = await allowanceService.set(cellCtx.value.row.employee.id, {
            salary_component_id: cellCtx.value.component.id,
            amount: cellForm.entitled ? cellForm.amount : 0,
            effective_from: cellForm.effective_from,
            note: cellForm.note,
        });
        data.pending ? toast.info(data.message) : toast.success(data.message);
        cellOpen.value = false;
        load({ silent: true });
    } catch (e) {
        cellErrors.value = e.response?.data?.errors ?? {};
        if (e.response?.status !== 422) toast.error(e.response?.data?.message ?? "Không lưu được.");
    } finally {
        saving.value = false;
    }
}

// ---- hàng loạt
const bulk = reactive({ componentId: null, mode: "suggested", amount: null, effective_from: "", note: "" });
const bulkOpen = ref(false);
const bulkFailed = ref([]);
const bulkComponent = computed(() => components.value.find((c) => c.id === bulk.componentId));
const bulkTitle = computed(
    () => `${BULK_MODES.find((m) => m.value === bulk.mode)?.title}: ${bulkComponent.value?.name ?? ""}`,
);
const bulkDescription = computed(
    () =>
        ({
            suggested: `${selected.value.length} người đã chọn sẽ được đặt đúng mức gợi ý theo loại hợp đồng của từng người.`,
            amount: `Đặt cùng một mức cho ${selected.value.length} người. Người có mức gợi ý khác cần lý do.`,
            none: `${selected.value.length} người sẽ không được hưởng khoản này từ ngày áp dụng.`,
        })[bulk.mode],
);

function openBulk() {
    Object.assign(bulk, { amount: null, effective_from: firstOfMonth(), note: "" });
    bulkFailed.value = [];
    bulkOpen.value = true;
}

async function runBulk() {
    saving.value = true;
    try {
        const { data } = await allowanceService.bulkSet({
            employee_ids: selected.value,
            salary_component_id: bulk.componentId,
            mode: bulk.mode,
            amount: bulk.mode === "amount" ? bulk.amount : undefined,
            effective_from: bulk.effective_from,
            note: bulk.note || undefined,
        });
        bulkFailed.value = data.failed;
        if (data.done) data.pending ? toast.info(data.message) : toast.success(data.message);
        if (!data.failed.length) {
            bulkOpen.value = false;
            selected.value = [];
        }
        load({ silent: true });
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error(errors ? Object.values(errors)[0][0] : (e.response?.data?.message ?? "Không thực hiện được."));
    } finally {
        saving.value = false;
    }
}

function flatten(nodes, depth = 0) {
    return nodes.flatMap((n) => [{ title: n.name, value: n.id, depth }, ...flatten(n.children ?? [], depth + 1)]);
}

watch([search, departmentId, contractType, onlyDiffers], () => {
    page.value = 1;
    load();
});
watch(page, () => load());
// Quay lại tab này (vd vừa thêm khoản ở tab Danh mục) thì tải lại để có cột mới.
watch(
    () => props.active,
    (value) => value && load({ silent: true }),
);

// Chấm công (vào/ra, HR duyệt, đổi nơi làm việc) đổi cột "Ước tính tháng này" -> tải lại ngay; nhiều lượt
// chấm công dồn dập lúc đầu giờ được gộp thành 1 lần tải.
useRealtimeRefresh(() => load({ silent: true }), {
    shared: [
        { resource: "employee_allowances", permission: "payroll.view_all" },
        { resource: "attendances", permission: "attendance.view_all" },
        { resource: "allowance_requests", permission: "payroll.view_all" },
    ],
    debounceMs: 1000,
});

onMounted(async () => {
    load();
    try {
        const { data } = await departmentService.tree();
        departments.value = flatten(Array.isArray(data) ? data : (data.data ?? []));
    } catch {
        // Bộ lọc phòng ban không bắt buộc.
    }
});
</script>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.filters__item {
    max-width: 240px;
    min-width: 180px;
}
.bulk-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-radius: 12px;
    background: rgba(var(--v-theme-primary), 0.06);
}
.bulk-bar__field {
    max-width: 230px;
    min-width: 170px;
}
.table-wrap {
    overflow-x: auto;
}
.matrix {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.matrix th,
.matrix td {
    padding: 10px 12px;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    text-align: left;
    vertical-align: middle;
}
.matrix th {
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
    white-space: nowrap;
}
.th-sub {
    font-weight: 400;
    font-size: 11px;
}
.matrix tbody tr:last-child td {
    border-bottom: 0;
}
.col-check {
    width: 44px;
    padding-right: 0 !important;
}
.col-employee {
    min-width: 220px;
    position: sticky;
    left: 0;
    background: rgb(var(--v-theme-surface));
    z-index: 1;
}
.col-cell {
    min-width: 132px;
}
.col-total {
    text-align: right !important;
    font-weight: 600;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.total__sub {
    font-size: 0.75rem;
    font-weight: 400;
    color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.total__sub.text-warning {
    color: rgb(var(--v-theme-warning)) !important;
}
.employee-link {
    color: inherit;
    font-weight: 500;
    text-decoration: none;
}
.employee-link:hover {
    color: rgb(var(--v-theme-primary));
    text-decoration: underline;
}
.cell {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    padding: 6px 8px;
    border-radius: 8px;
    border: 1px solid transparent;
    text-align: left;
    font: inherit;
    color: inherit;
    background: none;
    font-variant-numeric: tabular-nums;
}
.cell--clickable {
    cursor: pointer;
}
.cell--clickable:hover,
.cell--clickable:focus-visible {
    border-color: rgb(var(--v-theme-hairline));
    background: rgba(var(--v-theme-primary), 0.04);
    outline: none;
}
.cell__amount {
    font-weight: 600;
}
.cell--off {
    color: rgb(var(--v-theme-ink-muted));
}
.cell--unset {
    color: rgb(var(--v-theme-ink-muted));
    font-style: italic;
}
.cell__sub {
    font-size: 11px;
    color: rgb(var(--v-theme-ink-muted));
}
.cell__pending {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    margin-top: 2px;
    font-size: 11px;
    font-style: normal;
    font-weight: 500;
    color: rgb(var(--v-theme-info));
}
.cell__hint {
    font-size: 11px;
    font-style: normal;
    color: rgb(var(--v-theme-warning));
}
.empty {
    text-align: center !important;
    padding: 40px 16px !important;
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 600px) {
    .filters__item {
        max-width: none;
        flex: 1 1 100%;
    }
}
</style>
