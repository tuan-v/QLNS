<template>
    <!-- Tài khoản ngân hàng nhận lương — dùng cho tab ở Chi tiết nhân viên (HR,
         employeeId có giá trị) và "Hồ sơ của tôi" (employeeId = null). -->
    <div class="d-flex flex-column ga-4">
        <div class="d-flex flex-wrap align-center ga-3">
            <div class="flex-grow-1">
                <div class="text-subtitle-1 font-weight-bold">Tài khoản ngân hàng nhận lương</div>
                <div class="text-caption" style="opacity: 0.7">
                    <template v-if="byHr">Nhân viên tự thêm sẽ ở trạng thái "Chờ xác nhận" — kiểm tra rồi xác nhận để dùng nhận lương.</template>
                    <template v-else>Tài khoản bạn thêm cần phòng Nhân sự xác nhận trước khi dùng để nhận lương.</template>
                </div>
            </div>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreate">Thêm tài khoản</v-btn>
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact">{{ error }}</v-alert>
        <v-alert
            v-if="!loading && accounts.length && !accounts.some((a) => a.is_primary)"
            type="warning"
            variant="tonal"
            density="compact"
            icon="mdi-bank-off-outline"
        >
            Chưa có tài khoản nhận lương nào được xác nhận.
        </v-alert>

        <div v-if="loading" class="d-flex justify-center py-6">
            <v-progress-circular indeterminate size="24" />
        </div>
        <v-sheet v-else-if="!accounts.length" class="border rounded-lg pa-6 text-center" color="transparent" style="opacity: 0.7">
            Chưa có tài khoản ngân hàng nào.
        </v-sheet>

        <v-row v-else dense>
            <v-col v-for="account in accounts" :key="account.id" cols="12" md="6">
                <v-sheet
                    :class="['border rounded-lg pa-4 h-100 d-flex flex-column ga-2 glass-panel', { 'bank-card--primary': account.is_primary }]"
                    color="transparent"
                >
                    <div class="d-flex align-center ga-3">
                        <v-avatar color="primary" variant="tonal" rounded="lg" size="44">
                            <span class="text-caption font-weight-bold">{{ account.bank_code }}</span>
                        </v-avatar>
                        <div class="flex-grow-1 min-width-0">
                            <div class="text-body-2 font-weight-bold text-truncate">{{ account.bank_name }}</div>
                            <div class="text-caption" style="opacity: 0.7">{{ account.bank_branch || "Không ghi chi nhánh" }}</div>
                        </div>
                        <v-chip v-if="account.is_primary" color="success" size="small" variant="flat" prepend-icon="mdi-cash-check">
                            Nhận lương
                        </v-chip>
                    </div>
                    <div class="text-h6 font-weight-bold" style="letter-spacing: 0.08em">{{ groupDigits(account.account_number) }}</div>
                    <div class="text-body-2">{{ account.account_holder }}</div>
                    <div class="d-flex flex-wrap align-center ga-2">
                        <StatusChip :status="account.status" :map="STATUS_MAP" />
                        <span v-if="account.status === 'verified' && account.verified_by" class="text-caption" style="opacity: 0.7">
                            bởi {{ account.verified_by }}
                        </span>
                    </div>
                    <div v-if="account.status === 'rejected' && account.review_note" class="text-caption text-error">
                        Lý do: {{ account.review_note }}
                    </div>

                    <v-spacer />
                    <div class="d-flex flex-wrap ga-1">
                        <template v-if="byHr && account.status === 'pending'">
                            <v-btn color="success" variant="tonal" size="small" prepend-icon="mdi-check" :loading="busy === account.id" @click="verify(account)">
                                Xác nhận
                            </v-btn>
                            <v-btn color="error" variant="text" size="small" prepend-icon="mdi-close" @click="openReject(account)">Từ chối</v-btn>
                        </template>
                        <v-btn
                            v-if="account.status === 'verified' && !account.is_primary"
                            variant="text"
                            size="small"
                            prepend-icon="mdi-cash-check"
                            :loading="busy === account.id"
                            @click="setPrimary(account)"
                        >
                            Dùng để nhận lương
                        </v-btn>
                        <v-btn v-if="byHr || account.status !== 'verified'" variant="text" size="small" prepend-icon="mdi-pencil-outline" @click="openEdit(account)">
                            Sửa
                        </v-btn>
                        <v-btn
                            v-if="byHr || !account.is_primary"
                            variant="text"
                            color="error"
                            size="small"
                            prepend-icon="mdi-delete-outline"
                            @click="confirmDelete = account"
                        >
                            Xóa
                        </v-btn>
                    </div>
                </v-sheet>
            </v-col>
        </v-row>

        <BankAccountDialog
            v-model="dialogOpen"
            :employee-id="employeeId"
            :account="editing"
            :banks="banks"
            :default-holder="employeeName"
            @saved="onSaved"
        />

        <v-dialog :model-value="!!rejecting" max-width="440" @update:model-value="rejecting = null">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Từ chối tài khoản {{ rejecting?.bank_code }} · {{ rejecting?.account_number }}</v-card-title>
                <v-card-text>
                    <v-textarea
                        v-model="rejectNote"
                        label="Lý do (nhân viên sẽ thấy)"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        :error-messages="rejectError"
                    />
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="rejecting = null">Hủy</v-btn>
                    <v-btn color="error" variant="flat" :loading="busy === rejecting?.id" @click="reject">Từ chối</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog :model-value="!!confirmDelete" max-width="420" @update:model-value="confirmDelete = null">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Xóa tài khoản ngân hàng?</v-card-title>
                <v-card-text>
                    {{ confirmDelete?.bank_code }} · {{ confirmDelete?.account_number }}
                    <template v-if="confirmDelete?.is_primary">
                        — đây là tài khoản nhận lương, hệ thống sẽ tự chuyển sang tài khoản đã xác nhận khác (nếu có).
                    </template>
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="confirmDelete = null">Hủy</v-btn>
                    <v-btn color="error" variant="flat" :loading="busy === confirmDelete?.id" @click="remove">Xóa</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import bankAccountService from "../../services/bankAccountService";
import { useToastStore } from "../../stores/useToastStore";
import StatusChip from "../common/StatusChip.vue";
import BankAccountDialog from "./BankAccountDialog.vue";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const props = defineProps({
    // null = "Hồ sơ của tôi".
    employeeId: { type: [Number, String, null], default: null },
    // Gợi ý sẵn tên chủ tài khoản khi thêm mới.
    employeeName: { type: String, default: "" },
});

const toast = useToastStore();

const STATUS_MAP = {
    pending: { label: "Chờ xác nhận", color: "warning" },
    verified: { label: "Đã xác nhận", color: "success" },
    rejected: { label: "Bị từ chối", color: "error" },
};

const byHr = computed(() => props.employeeId !== null);
const accounts = ref([]);
const banks = ref([]);
const loading = ref(false);
const error = ref("");
const busy = ref(null);
const dialogOpen = ref(false);
const editing = ref(null);
const rejecting = ref(null);
const rejectNote = ref("");
const rejectError = ref("");
const confirmDelete = ref(null);
let loadedOnce = false;

function firstError(e) {
    const errors = e.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message;
}

function groupDigits(value) {
    return String(value ?? "").replace(/(\d{4})(?=\d)/g, "$1 ");
}

async function load() {
    // Chỉ hiện vòng quay ở lần tải đầu; các lần sau tải ngầm, giữ nguyên danh sách đang thấy.
    loading.value = !loadedOnce;
    error.value = "";
    try {
        accounts.value = (await bankAccountService.list(props.employeeId)).data.data;
        loadedOnce = true;
    } catch (e) {
        error.value = firstError(e) ?? "Không tải được tài khoản ngân hàng.";
    } finally {
        loading.value = false;
    }
}

async function loadBanks() {
    try {
        banks.value = (await bankAccountService.banks()).data.data;
    } catch {
        banks.value = [];
    }
}

function openCreate() {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(account) {
    editing.value = account;
    dialogOpen.value = true;
}

function onSaved(saved) {
    // Hiện ngay tài khoản vừa lưu, rồi tải lại ngầm (tài khoản chính có thể đổi).
    const index = accounts.value.findIndex((a) => a.id === saved.id);
    accounts.value = index === -1 ? [...accounts.value, saved] : accounts.value.map((a) => (a.id === saved.id ? saved : a));
    toast.success(byHr.value ? "Đã lưu tài khoản ngân hàng." : "Đã gửi tài khoản, chờ phòng Nhân sự xác nhận.");
    load();
}

async function run(account, action, successMessage) {
    busy.value = account.id;
    error.value = "";
    try {
        await action();
        toast.success(successMessage);
        await load();
        return true;
    } catch (e) {
        error.value = firstError(e) ?? "Thao tác không thành công.";
        return false;
    } finally {
        busy.value = null;
    }
}

function verify(account) {
    run(account, () => bankAccountService.review(props.employeeId, account.id, { status: "verified" }), "Đã xác nhận tài khoản.");
}

function openReject(account) {
    rejecting.value = account;
    rejectNote.value = "";
    rejectError.value = "";
}

async function reject() {
    if (!rejectNote.value.trim()) {
        rejectError.value = "Vui lòng nhập lý do từ chối";
        return;
    }
    const account = rejecting.value;
    const ok = await run(account, () => bankAccountService.review(props.employeeId, account.id, { status: "rejected", review_note: rejectNote.value.trim() }), "Đã từ chối tài khoản.");
    if (ok) rejecting.value = null;
}

function setPrimary(account) {
    run(account, () => bankAccountService.setPrimary(props.employeeId, account.id), "Đã đổi tài khoản nhận lương.");
}

async function remove() {
    const account = confirmDelete.value;
    await run(account, () => bankAccountService.remove(props.employeeId, account.id), "Đã xóa tài khoản ngân hàng.");
    confirmDelete.value = null;
}

useRealtimeRefresh(load, byHr.value
    ? { shared: [{ resource: "employee_bank_accounts", permission: "employee.update" }] }
    : { mine: ["bank_accounts"] });

onMounted(() => {
    load();
    loadBanks();
});
</script>

<style scoped>
.bank-card--primary {
    border-color: rgb(var(--v-theme-success)) !important;
}
</style>
