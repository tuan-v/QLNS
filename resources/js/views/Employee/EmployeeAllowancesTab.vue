<template>
    <div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>
        <v-progress-linear v-if="loading && !data" indeterminate color="primary" />

        <template v-if="data">
            <div class="summary">
                <!-- Ước tính tháng này (2026-10-22) — cùng cách tính cột cùng tên ở trang Phụ cấp. -->
                <div class="summary__estimate">
                    <div class="text-caption text-medium-emphasis">
                        Ước tính tháng này · {{ monthLabel }}, tới hôm nay
                    </div>
                    <div class="summary__value">{{ Number(data.estimate.amount).toLocaleString("vi-VN") }}đ</div>
                    <div class="text-caption text-medium-emphasis">
                        <template v-if="hasDaily">{{ data.estimate.office_days }} ngày làm tại công ty</template>
                        <template v-else>Không hưởng khoản theo ngày</template>
                        <span
                            v-if="hasDaily && data.estimate.pending_days"
                            class="text-warning"
                            :title="`Nếu HR duyệt thì thêm khoảng ${money(data.estimate.pending_amount)}`"
                        >
                            · +{{ data.estimate.pending_days }} ngày chờ duyệt
                        </span>
                    </div>
                </div>
                <div class="summary__basis">
                    <div class="text-caption text-medium-emphasis">Gợi ý dựa trên</div>
                    <div>{{ data.profile.label }}</div>
                </div>
                <v-spacer />
                <v-btn v-if="canManage && differsCount" variant="flat" prepend-icon="mdi-auto-fix" @click="openApply">
                    Áp dụng mức gợi ý ({{ differsCount }} khoản)
                </v-btn>
            </div>

            <div class="table-wrap">
                <table class="allowance-table">
                    <thead>
                        <tr>
                            <th>Khoản</th>
                            <th class="num">Gợi ý</th>
                            <th class="num">Đang áp dụng</th>
                            <th>Từ ngày</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="item in data.items" :key="item.component.id">
                            <tr>
                                <td class="name-cell">
                                    <div class="font-weight-medium">{{ item.component.name }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ item.component.type_label }} · {{ item.component.method_label }}
                                    </div>
                                </td>
                                <td class="num text-medium-emphasis">
                                    {{ amountText(item.suggested_amount, item.component.unit) }}
                                </td>
                                <td class="num">
                                    <!-- Cùng cách gọi với trang Phụ cấp & trợ cấp → tab Theo nhân viên. -->
                                    <template v-if="item.is_default">
                                        <span class="font-weight-medium">
                                            {{ amountText(item.effective_amount, item.component.unit) }}
                                        </span>
                                        <div class="text-caption text-medium-emphasis">mặc định cho mọi nhân viên</div>
                                    </template>
                                    <span v-else-if="!item.current" class="text-medium-emphasis font-italic"
                                        >Chưa đặt</span
                                    >
                                    <span v-else-if="!item.current.amount" class="text-medium-emphasis"
                                        >Không hưởng</span
                                    >
                                    <span v-else class="font-weight-medium">
                                        {{ amountText(item.current.amount, item.component.unit) }}
                                    </span>
                                    <v-chip
                                        v-if="item.differs && (item.current?.amount || item.suggested_amount)"
                                        size="x-small"
                                        color="warning"
                                        variant="tonal"
                                        label
                                        class="ml-2"
                                        :title="item.current?.note || ''"
                                    >
                                        Khác gợi ý
                                    </v-chip>
                                    <div v-if="item.current?.note" class="text-caption text-medium-emphasis note">
                                        {{ item.current.note }}
                                    </div>
                                    <div v-if="item.upcoming" class="text-caption text-info">
                                        Từ {{ formatDate(item.upcoming.effective_from) }}:
                                        {{ amountText(item.upcoming.amount, item.component.unit) }}
                                    </div>
                                    <div v-if="item.pending" class="text-caption text-warning">
                                        <v-icon icon="mdi-clock-outline" size="12" />
                                        Chờ Admin duyệt:
                                        {{
                                            item.pending.amount
                                                ? amountText(item.pending.amount, item.component.unit)
                                                : "Không hưởng"
                                        }}
                                        từ {{ formatDate(item.pending.effective_from) }}
                                        <template v-if="item.pending.requested_by">
                                            ({{ item.pending.requested_by }})</template
                                        >
                                    </div>
                                </td>
                                <td>{{ item.current ? formatDate(item.current.effective_from) : "—" }}</td>
                                <td class="actions">
                                    <v-btn
                                        v-if="item.history.length"
                                        variant="text"
                                        size="small"
                                        :append-icon="
                                            expanded[item.component.id] ? 'mdi-chevron-up' : 'mdi-chevron-down'
                                        "
                                        @click="expanded[item.component.id] = !expanded[item.component.id]"
                                    >
                                        Lịch sử
                                    </v-btn>
                                    <v-btn
                                        v-if="canManage && item.component.is_active"
                                        variant="text"
                                        size="small"
                                        color="primary"
                                        @click="openSet(item)"
                                    >
                                        Đặt mức
                                    </v-btn>
                                </td>
                            </tr>
                            <tr v-if="expanded[item.component.id]" class="history-row">
                                <td colspan="5">
                                    <div v-for="h in item.history" :key="h.id" class="history-line">
                                        <span class="font-weight-medium">{{
                                            h.amount ? amountText(h.amount, item.component.unit) : "Không hưởng"
                                        }}</span>
                                        <span class="text-medium-emphasis">
                                            {{ formatDate(h.effective_from) }} –
                                            {{ h.effective_to ? formatDate(h.effective_to) : "nay" }}
                                        </span>
                                        <span v-if="h.note">· {{ h.note }}</span>
                                        <span v-if="h.created_by" class="text-medium-emphasis"
                                            >· {{ h.created_by }}</span
                                        >
                                        <span
                                            v-if="h.approved_by && h.approved_by !== h.created_by"
                                            class="text-medium-emphasis"
                                            >· {{ h.approved_by }} duyệt</span
                                        >
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- Đặt mức 1 khoản -->
        <v-dialog v-model="setOpen" max-width="480">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Đặt mức {{ setItem?.component.name.toLowerCase() }}</v-card-title>
                <v-card-text>
                    <div class="d-flex align-center ga-2 mb-3 text-body-2">
                        <span class="text-medium-emphasis"
                            >Mức gợi ý: {{ amountText(setItem?.suggested_amount, setItem?.component.unit) }}</span
                        >
                        <v-btn
                            v-if="form.amount !== setItem?.suggested_amount"
                            size="x-small"
                            variant="tonal"
                            @click="form.amount = setItem.suggested_amount"
                        >
                            Dùng mức gợi ý
                        </v-btn>
                    </div>
                    <InputMoney
                        v-model="form.amount"
                        :label="
                            setItem?.component.unit === 'day' ? 'Số tiền mỗi ngày làm tại công ty' : 'Số tiền mỗi tháng'
                        "
                        suffix="đ"
                        :error-messages="errors.amount"
                    />
                    <div class="text-caption text-medium-emphasis mb-3">Nhập 0 để ngừng hưởng khoản này.</div>
                    <v-alert
                        v-if="setItem?.pending"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-3"
                        icon="mdi-clock-outline"
                    >
                        Đang có đề xuất chờ Admin duyệt cho khoản này — lưu lần nữa sẽ thay đề xuất đó.
                    </v-alert>
                    <div v-else-if="!canApprove" class="text-caption text-medium-emphasis mb-3">
                        Thay đổi được gửi tới Admin duyệt, chỉ áp dụng sau khi được duyệt.
                    </div>
                    <InputDate
                        v-model="form.effective_from"
                        label="Áp dụng từ ngày"
                        :error-messages="errors.effective_from"
                    />
                    <v-textarea
                        v-model="form.note"
                        :label="
                            needsNote ? 'Nội dung / lý do (bắt buộc vì khác mức gợi ý)' : 'Ghi chú (không bắt buộc)'
                        "
                        rows="2"
                        auto-grow
                        variant="outlined"
                        density="comfortable"
                        class="mt-2"
                        :error-messages="errors.note"
                    />
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="setOpen = false">Hủy</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="saving"
                        :disabled="form.amount === null || !form.effective_from || (needsNote && !form.note.trim())"
                        @click="save"
                    >
                        {{ canApprove ? "Lưu" : "Gửi duyệt" }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Áp dụng mức gợi ý cho mọi khoản đang khác -->
        <v-dialog v-model="applyOpen" max-width="440">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Áp dụng mức gợi ý</v-card-title>
                <v-card-text>
                    <p class="mb-4">
                        Đổi {{ differsCount }} khoản đang khác gợi ý về đúng mức gợi ý theo
                        {{ data?.profile.label.toLowerCase() }}.
                    </p>
                    <InputDate v-model="applyFrom" label="Áp dụng từ ngày" />
                    <div v-if="!canApprove" class="text-caption text-medium-emphasis">
                        Thay đổi được gửi tới Admin duyệt, chỉ áp dụng sau khi được duyệt.
                    </div>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="applyOpen = false">Hủy</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="saving"
                        :disabled="!applyFrom"
                        @click="applySuggested"
                    >
                        {{ canApprove ? "Áp dụng" : "Gửi duyệt" }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from "vue";
import InputMoney from "../../components/common/InputMoney.vue";
import InputDate from "../../components/common/InputDate.vue";
import allowanceService from "../../services/allowanceService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Tab "Phụ cấp" ở Chi tiết nhân viên: mức gợi ý (theo hợp đồng + Trưởng phòng) cạnh mức đang áp dụng,
// HR đặt mức từng khoản (lệch gợi ý bắt buộc lý do) và xem lịch sử thay đổi. HR (không có allowance.approve)
// đặt mức = gửi đề xuất chờ Admin duyệt (2026-10-21).
const props = defineProps({
    employeeId: { type: [Number, String], required: true },
});
const auth = useAuthStore();
const toast = useToastStore();
const canManage = computed(() => auth.permissions.includes("payroll.manage"));
const canApprove = computed(() => auth.permissions.includes("allowance.approve"));

const data = ref(null);
const loading = ref(false);
const error = ref("");
const expanded = reactive({});

const setOpen = ref(false);
const setItem = ref(null);
const form = reactive({ amount: null, effective_from: "", note: "" });
const errors = ref({});
const saving = ref(false);
const applyOpen = ref(false);
const applyFrom = ref("");

const money = (value) => (value ? `${Number(value).toLocaleString("vi-VN")}đ` : "—");
// "30.000đ/ngày" cho khoản tính theo ngày làm tại công ty.
const amountText = (value, unit) => (value ? `${money(value)}${unit === "day" ? "/ngày" : ""}` : "—");
const formatDate = (value) => (value ? new Date(value).toLocaleDateString("vi-VN") : "");
const firstOfMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
};

const differsCount = computed(() => data.value?.items.filter((i) => i.component.is_active && i.differs).length ?? 0);
const monthLabel = `Tháng ${new Date().getMonth() + 1}/${new Date().getFullYear()}`;
// Có khoản theo ngày đang hưởng (ăn trưa) thì mới hiện số ngày tại công ty dưới ô ước tính.
const hasDaily = computed(
    () => data.value?.items.some((i) => i.component.unit === "day" && i.effective_amount > 0) ?? false,
);
const needsNote = computed(
    () => setItem.value && Math.round(form.amount ?? 0) !== Math.round(setItem.value.suggested_amount),
);

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = "";
    try {
        data.value = (await allowanceService.forEmployee(props.employeeId)).data;
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được phụ cấp.";
    } finally {
        loading.value = false;
    }
}

function openSet(item) {
    setItem.value = item;
    errors.value = {};
    Object.assign(
        form,
        item.pending
            ? {
                  amount: item.pending.amount,
                  effective_from: item.pending.effective_from,
                  note: item.pending.note ?? "",
              }
            : {
                  amount: item.is_default ? item.effective_amount : (item.current?.amount ?? item.suggested_amount),
                  effective_from: firstOfMonth(),
                  note: "",
              },
    );
    setOpen.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        data.value = (
            await allowanceService.set(props.employeeId, {
                salary_component_id: setItem.value.component.id,
                amount: form.amount,
                effective_from: form.effective_from,
                note: form.note,
            })
        ).data;
        data.value.pending ? toast.info(data.value.message) : toast.success(data.value.message);
        setOpen.value = false;
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
        if (e.response?.status !== 422) toast.error(e.response?.data?.message ?? "Không lưu được.");
    } finally {
        saving.value = false;
    }
}

function openApply() {
    applyFrom.value = firstOfMonth();
    applyOpen.value = true;
}

async function applySuggested() {
    saving.value = true;
    try {
        const { data: result } = await allowanceService.applySuggested(props.employeeId, applyFrom.value);
        data.value = result;
        result.pending ? toast.info(result.message) : toast.success(result.message);
        applyOpen.value = false;
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không áp dụng được.");
    } finally {
        saving.value = false;
    }
}

useRealtimeRefresh(() => load({ silent: true }), {
    shared: [
        { resource: "employee_allowances", permission: "payroll.view_all" },
        { resource: "allowance_requests", permission: "payroll.view_all" },
        // Chấm công / duyệt công đổi số ngày tại công ty -> ô "Ước tính tháng này" cập nhật ngay.
        { resource: "attendances", permission: "attendance.view_all" },
    ],
    debounceMs: 1000,
});
onMounted(load);
</script>

<style scoped>
.summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px 32px;
    margin-bottom: 16px;
}
.summary__value {
    font-size: 1.25rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.summary__basis {
    font-size: 0.875rem;
}
.summary__estimate {
    padding: 10px 16px;
    border-radius: 12px;
    background: rgba(var(--v-theme-primary), 0.06);
}
.table-wrap {
    overflow-x: auto;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
}
.allowance-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.allowance-table th,
.allowance-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    vertical-align: top;
    white-space: nowrap;
}
.allowance-table th {
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
}
.allowance-table tbody tr:last-child td {
    border-bottom: 0;
}
.allowance-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.name-cell {
    white-space: normal !important;
    min-width: 180px;
}
.note {
    white-space: normal;
    max-width: 260px;
    margin-left: auto;
}
.actions {
    text-align: right !important;
    padding: 6px 8px !important;
}
.history-row td {
    background: rgba(var(--v-theme-on-surface), 0.02);
    padding-top: 8px !important;
    padding-bottom: 8px !important;
    white-space: normal;
}
.history-line {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 3px 0;
}
</style>
