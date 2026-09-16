<template>
    <div style="display: flex; flex-direction: column; gap: 1.5rem">
        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <div>
            <div class="text-subtitle-1 font-weight-bold mb-2">
                Quỹ phép năm {{ currentYear }}
            </div>
            <v-sheet class="border rounded-lg glass-panel" color="transparent">
                <v-table density="comfortable">
                    <thead>
                        <tr>
                            <th>Loại phép</th>
                            <th class="text-center">Đã cấp</th>
                            <th class="text-center">Đã dùng</th>
                            <th class="text-center">Đang chờ duyệt</th>
                            <th class="text-center">Còn lại (khả dụng)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="balancesLoading">
                            <td colspan="5" class="text-center py-6">
                                <v-progress-circular
                                    indeterminate
                                    size="24"
                                />
                            </td>
                        </tr>
                        <tr v-else-if="!balances.length">
                            <td
                                colspan="5"
                                class="text-center py-6"
                                style="opacity: 0.6"
                            >
                                Chưa có dữ liệu quỹ phép.
                            </td>
                        </tr>
                        <tr v-for="b in balances" v-else :key="b.leave_type.id">
                            <td>{{ b.leave_type.name }}</td>
                            <td class="text-center">
                                {{ allocatedTotal(b) }}
                            </td>
                            <td class="text-center">{{ b.used_days }}</td>
                            <td class="text-center">{{ b.pending_days }}</td>
                            <td class="text-center">
                                <!-- Loại phép không có hạn mức năm (vd Nghỉ ốm,
                                     Thai sản — tính theo chế độ riêng, không
                                     trừ vào quỹ phép năm, xem LeaveRequests.vue)
                                     thì "0 khả dụng" không phải cảnh báo, chỉ
                                     tô đậm cảnh báo đỏ khi THẬT SỰ có hạn mức
                                     mà đã dùng hết. -->
                                <strong
                                    v-if="allocatedTotal(b) > 0"
                                    :class="
                                        b.available_days > 0
                                            ? 'text-success'
                                            : 'text-error'
                                    "
                                >
                                    {{ b.available_days }}
                                </strong>
                                <span v-else class="text-medium-emphasis">
                                    —
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-sheet>
        </div>

        <div>
            <div class="text-subtitle-1 font-weight-bold mb-2">
                Lịch sử phiếu lương
            </div>
            <v-sheet class="border rounded-lg glass-panel" color="transparent">
                <v-table density="comfortable">
                    <thead>
                        <tr>
                            <th>Kỳ lương</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thực nhận</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="payslipsLoading">
                            <td colspan="4" class="text-center py-6">
                                <v-progress-circular
                                    indeterminate
                                    size="24"
                                />
                            </td>
                        </tr>
                        <tr v-else-if="!payslips.length">
                            <td
                                colspan="4"
                                class="text-center py-6"
                                style="opacity: 0.6"
                            >
                                Chưa có phiếu lương nào.
                            </td>
                        </tr>
                        <tr v-for="p in payslips" v-else :key="p.id">
                            <td>
                                Tháng {{ p.payroll.period_month }}/{{
                                    p.payroll.period_year
                                }}
                            </td>
                            <td>
                                <StatusChip
                                    :status="p.payroll.status"
                                    :map="PAYROLL_STATUS_MAP"
                                />
                            </td>
                            <td class="text-center">
                                <strong>{{ formatMoney(p.net_salary) }}</strong>
                            </td>
                            <td class="text-center">
                                <v-btn
                                    icon="mdi-file-document-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    @click="openPayslip(p)"
                                >
                                    <v-icon icon="mdi-file-document-outline" />
                                    <v-tooltip
                                        activator="parent"
                                        location="top"
                                    >
                                        Xem phiếu lương
                                    </v-tooltip>
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>
            </v-sheet>
        </div>

        <PayrollPayslipDialog
            v-model="payslipDialog"
            :detail="selectedDetail"
            :payroll="selectedDetail?.payroll"
        />
    </div>
</template>

<script setup>
// Tab "Lương / Phép" của EmployeeDetail.vue — trước đây chỉ là placeholder
// tĩnh (v-alert "Chưa triển khai") để lại từ hồi Ngày 27, lúc đó Payroll/Nghỉ
// phép chưa xây. Giờ cả 2 module đã xong, ghép lại bằng cách gọi 2 API mới
// (dành cho HR xem 1 nhân viên bất kỳ, khác các API "/me" tự phục vụ):
// GET /leave-requests/balances/{employee} và GET /employees/{employee}/payslips.
// Dialog "Xem phiếu lương" tái dùng PayrollPayslipDialog.vue — cùng component
// MyProfilePayslipsTab.vue đang dùng cho nhân viên tự xem.
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import leaveRequestService from "../../services/leaveRequestService";
import StatusChip from "../../components/common/StatusChip.vue";
import PayrollPayslipDialog from "../Payroll/PayrollPayslipDialog.vue";

const props = defineProps({
    employeeId: {
        type: [String, Number],
        required: true,
    },
});

const PAYROLL_STATUS_MAP = {
    closed: { label: "Đã chốt", color: "info" },
    paid: { label: "Đã trả", color: "success" },
};

const currentYear = new Date().getFullYear();
const loadError = ref("");

function formatMoney(value) {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

function allocatedTotal(balance) {
    return (
        balance.allocated_days +
        balance.carried_forward_days +
        balance.adjusted_days
    );
}

const balances = ref([]);
const balancesLoading = ref(false);

async function loadBalances() {
    balancesLoading.value = true;
    try {
        const response = await leaveRequestService.balancesForEmployee(
            props.employeeId,
        );
        balances.value = response.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không thể tải quỹ phép.";
    } finally {
        balancesLoading.value = false;
    }
}

const payslips = ref([]);
const payslipsLoading = ref(false);

async function loadPayslips() {
    payslipsLoading.value = true;
    try {
        const response = await employeeService.payslips(props.employeeId);
        payslips.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không thể tải lịch sử phiếu lương.";
    } finally {
        payslipsLoading.value = false;
    }
}

const payslipDialog = ref(false);
const selectedDetail = ref(null);

function openPayslip(detail) {
    selectedDetail.value = detail;
    payslipDialog.value = true;
}

onMounted(() => {
    loadBalances();
    loadPayslips();
});
</script>
