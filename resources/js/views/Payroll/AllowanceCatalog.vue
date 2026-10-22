<template>
    <div>
        <div class="d-flex flex-wrap align-center ga-3 mb-4">
            <div class="text-body-2 text-medium-emphasis flex-grow-1">
                Mỗi khoản khai cách tính, thuế, bảo hiểm và mức gợi ý theo loại nhân viên. Ai được hưởng khoản nào đặt ở
                tab “Theo nhân viên”.
            </div>
            <v-btn v-if="canManage" color="primary" prepend-icon="mdi-plus" @click="openForm(null)">Thêm khoản</v-btn>
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>
        <v-progress-linear v-if="loading && !items.length" indeterminate color="primary" class="mb-4" />

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <div class="table-wrap">
                <table class="allowance-table">
                    <thead>
                        <tr>
                            <th>Khoản</th>
                            <th>Cách tính</th>
                            <th>Mức gợi ý / tháng</th>
                            <th>Thuế / BH</th>
                            <th class="num">Đang hưởng</th>
                            <th v-if="canManage" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id" :class="{ 'row--inactive': !item.is_active }">
                            <td class="name-cell">
                                <div class="font-weight-medium">
                                    {{ item.name }}
                                    <span class="type-tag">{{ item.type_label }}</span>
                                    <span v-if="!item.is_active" class="type-tag">Ngừng sử dụng</span>
                                </div>
                                <div v-if="item.description" class="text-caption text-medium-emphasis">
                                    {{ item.description }}
                                </div>
                            </td>
                            <td>{{ item.method_label }}</td>
                            <td>
                                <div class="suggestions">
                                    <template v-for="(label, key) in meta.suggestion_keys" :key="key">
                                        <span class="text-medium-emphasis">{{ label }}</span>
                                        <span class="num">
                                            {{
                                                key === "head" && item.suggested_amounts?.head == null
                                                    ? "như hợp đồng"
                                                    : money(item.suggested_amounts?.[key]) +
                                                      (item.unit === "day" && item.suggested_amounts?.[key]
                                                          ? "/ngày"
                                                          : "")
                                            }}
                                        </span>
                                    </template>
                                </div>
                            </td>
                            <td class="tax-cell">
                                <div>{{ taxText(item) }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ item.in_insurance_salary ? "Tính vào lương đóng BH" : "Không đóng BH" }}
                                </div>
                            </td>
                            <td class="num">
                                <template v-if="item.applies_to_all">
                                    <div>Mọi nhân viên</div>
                                    <div v-if="item.off_count" class="text-caption text-medium-emphasis">
                                        trừ {{ item.off_count }} người không hưởng
                                    </div>
                                </template>
                                <template v-else>{{ item.active_count }} người</template>
                            </td>
                            <td v-if="canManage" class="actions">
                                <v-btn
                                    v-if="!item.applies_to_all"
                                    icon="mdi-account-multiple-plus-outline"
                                    variant="text"
                                    size="small"
                                    :disabled="!item.is_active"
                                    title="Áp dụng mức gợi ý cho nhân viên chưa có khoản này"
                                    aria-label="Áp dụng mức gợi ý cho nhân viên chưa có"
                                    @click="openApply(item)"
                                />
                                <v-btn
                                    icon="mdi-pencil-outline"
                                    variant="text"
                                    size="small"
                                    title="Sửa"
                                    aria-label="Sửa"
                                    @click="openForm(item)"
                                />
                                <v-btn
                                    icon="mdi-delete-outline"
                                    variant="text"
                                    size="small"
                                    title="Xóa"
                                    aria-label="Xóa"
                                    @click="askDelete(item)"
                                />
                            </td>
                        </tr>
                        <tr v-if="!loading && !items.length">
                            <td :colspan="canManage ? 6 : 5" class="empty">Chưa có khoản phụ cấp nào.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </v-sheet>

        <AllowanceForm
            v-model="formOpen"
            :component="editing"
            :types="meta.types"
            :methods="meta.methods"
            :suggestion-keys="meta.suggestion_keys"
            @saved="load"
        />

        <!-- Áp dụng mức gợi ý cho mọi nhân viên chưa có khoản này -->
        <v-dialog v-model="applyOpen" max-width="460">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Áp dụng mức gợi ý</v-card-title>
                <v-card-text>
                    <p class="mb-4">
                        Đặt khoản <strong>{{ applying?.name }}</strong> theo mức gợi ý cho mọi nhân viên đang làm việc
                        <strong>chưa có</strong> khoản này. Người đã có mức riêng giữ nguyên.
                    </p>
                    <InputDate v-model="applyFrom" label="Áp dụng từ ngày" />
                    <div v-if="!canApprove" class="text-caption text-medium-emphasis">
                        Mỗi người sẽ thành một đề xuất chờ Admin duyệt, chỉ áp dụng sau khi được duyệt.
                    </div>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="applyOpen = false">Hủy</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="applyLoading"
                        :disabled="!applyFrom"
                        @click="applyToAll"
                    >
                        {{ canApprove ? "Áp dụng" : "Gửi duyệt" }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="deleteOpen" max-width="440">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Xóa khoản phụ cấp?</v-card-title>
                <v-card-text>
                    Xóa <strong>{{ deleting?.name }}</strong> khỏi danh mục. Khoản đã có người hưởng hoặc đã vào bảng
                    lương thì không xóa được, hãy sửa sang "Ngừng sử dụng".
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="deleteOpen = false">Hủy</v-btn>
                    <v-btn color="error" variant="flat" :loading="deleteLoading" @click="remove">Xóa</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import InputDate from "../../components/common/InputDate.vue";
import AllowanceForm from "./AllowanceForm.vue";
import allowanceService from "../../services/allowanceService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Tab "Danh mục khoản" của trang Phụ cấp & trợ cấp (Kế hoạch Ngày 48). Xem: payroll.view_all; sửa: payroll.manage.
const auth = useAuthStore();
const toast = useToastStore();
const canManage = computed(() => auth.permissions.includes("payroll.manage"));
// HR không có quyền duyệt -> "áp dụng mức gợi ý cho mọi người" thành đề xuất chờ Admin duyệt (2026-10-21).
const canApprove = computed(() => auth.permissions.includes("allowance.approve"));

const items = ref([]);
const meta = ref({ types: {}, methods: {}, suggestion_keys: {} });
const loading = ref(false);
const error = ref("");

const formOpen = ref(false);
const editing = ref(null);
const applyOpen = ref(false);
const applying = ref(null);
const applyFrom = ref("");
const applyLoading = ref(false);
const deleteOpen = ref(false);
const deleting = ref(null);
const deleteLoading = ref(false);

const money = (value) => (value ? `${Number(value).toLocaleString("vi-VN")}đ` : "—");

function taxText(item) {
    if (!item.is_taxable) return "Không chịu thuế";
    return item.tax_free_amount ? `Miễn thuế tới ${money(item.tax_free_amount)}` : "Chịu thuế";
}

function firstOfMonth() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = "";
    try {
        const { data } = await allowanceService.catalog();
        items.value = data.data;
        meta.value = { types: data.types, methods: data.methods, suggestion_keys: data.suggestion_keys };
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được danh mục phụ cấp.";
    } finally {
        loading.value = false;
    }
}

function openForm(item) {
    editing.value = item;
    formOpen.value = true;
}

function openApply(item) {
    applying.value = item;
    applyFrom.value = firstOfMonth();
    applyOpen.value = true;
}

async function applyToAll() {
    applyLoading.value = true;
    try {
        const { data } = await allowanceService.applyToAll(applying.value.id, applyFrom.value);
        data.pending ? toast.info(data.message) : toast.success(data.message);
        applyOpen.value = false;
        load({ silent: true });
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không áp dụng được.");
    } finally {
        applyLoading.value = false;
    }
}

function askDelete(item) {
    deleting.value = item;
    deleteOpen.value = true;
}

async function remove() {
    deleteLoading.value = true;
    try {
        await allowanceService.remove(deleting.value.id);
        toast.success("Đã xóa khoản phụ cấp.");
        deleteOpen.value = false;
        load({ silent: true });
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error(errors ? Object.values(errors)[0][0] : (e.response?.data?.message ?? "Không xóa được."));
    } finally {
        deleteLoading.value = false;
    }
}

useRealtimeRefresh(() => load({ silent: true }), {
    shared: [{ resource: "employee_allowances", permission: "payroll.view_all" }],
});
onMounted(load);
</script>

<style scoped>
.table-wrap {
    overflow-x: auto;
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
    min-width: 220px;
}
.suggestions {
    display: grid;
    grid-template-columns: auto auto;
    gap: 2px 16px;
    font-size: 0.8125rem;
}
.suggestions .num {
    text-align: right;
}
.tax-cell {
    min-width: 170px;
}
.type-tag {
    display: inline-block;
    margin-left: 6px;
    padding: 0 6px;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 4px;
    font-size: 11px;
    font-weight: 500;
    color: rgb(var(--v-theme-ink-muted));
}
.row--inactive td {
    opacity: 0.6;
}
.actions {
    text-align: right !important;
    padding: 6px 8px !important;
}
.empty {
    text-align: center !important;
    padding: 40px 16px !important;
    color: rgb(var(--v-theme-ink-muted));
}
</style>
