<template>
    <div>
        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

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
                    <tr v-if="loading">
                        <td colspan="4" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
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
                                <v-tooltip activator="parent" location="top">
                                    Xem phiếu lương
                                </v-tooltip>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <PayrollPayslipDialog
            v-model="payslipDialog"
            :detail="selectedDetail"
            :payroll="selectedDetail?.payroll"
        />
    </div>
</template>

<script setup>
// Tab "Phiếu lương" của MyProfile.vue — tách riêng theo đúng khuôn các tab tự
// phục vụ khác. Tái dùng PayrollPayslipDialog.vue của module Payroll (nằm ở
// thư mục ../Payroll/) thay vì viết lại — cùng 1 component cho cả HR xem phiếu
// lương của nhân viên bất kỳ lẫn nhân viên tự xem của chính mình.
import { onMounted, ref } from "vue";
import payrollService from "../../services/payrollService";
import StatusChip from "../../components/common/StatusChip.vue";
import PayrollPayslipDialog from "../Payroll/PayrollPayslipDialog.vue";

const PAYROLL_STATUS_MAP = {
    closed: { label: "Đã chốt", color: "info" },
    paid: { label: "Đã trả", color: "success" },
};

const payslips = ref([]);
const loading = ref(false);
const loadError = ref("");

function formatMoney(value) {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await payrollService.mine();
        payslips.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không thể tải phiếu lương.";
    } finally {
        loading.value = false;
    }
}

const payslipDialog = ref(false);
const selectedDetail = ref(null);

function openPayslip(detail) {
    selectedDetail.value = detail;
    payslipDialog.value = true;
}

onMounted(loadData);
</script>
