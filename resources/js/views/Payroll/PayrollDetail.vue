<template>
    <div>
        <PageHeader :title="pageTitle" :subtitle="pageSubtitle">
            <template #actions>
                <v-btn
                    variant="text"
                    prepend-icon="mdi-arrow-left"
                    to="/payrolls"
                >
                    Quay lại
                </v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-file-excel-outline"
                    :disabled="!payroll?.details?.length"
                    @click="exportExcel"
                >
                    Xuất Excel
                </v-btn>
            </template>
        </PageHeader>

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

        <DataTable
            :headers="headers"
            :items="payroll?.details ?? []"
            :loading="loading"
            :actions="actions"
        >
            <template #item.employee="{ item }">
                {{ item.employee?.code }} — {{ item.employee?.full_name }}
            </template>
            <template #item.base_salary="{ item }">
                {{ formatMoney(item.base_salary) }}
            </template>
            <template #item.work_days="{ item }">
                {{ item.actual_work_days }} / {{ item.standard_work_days }}
            </template>
            <template #item.overtime_minutes="{ item }">
                {{ formatMinutesAsHours(item.overtime_minutes) }}
            </template>
            <template #item.net_salary="{ item }">
                <strong>{{ formatMoney(item.net_salary) }}</strong>
            </template>
        </DataTable>

        <PayrollPayslipDialog
            v-model="payslipDialog"
            :detail="selectedDetail"
            :payroll="payroll"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import payrollService from "../../services/payrollService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import PayrollPayslipDialog from "./PayrollPayslipDialog.vue";
import { formatMinutesAsHours } from "../../composables/useCheckIn.js";

const route = useRoute();

const payroll = ref(null);
const loading = ref(false);
const loadError = ref("");

const pageTitle = computed(() =>
    payroll.value
        ? `Bảng lương Tháng ${payroll.value.period_month}/${payroll.value.period_year}`
        : "Chi tiết bảng lương",
);
const pageSubtitle = computed(() =>
    payroll.value
        ? `Tổng chi phí lương: ${formatMoney(payroll.value.total_payroll_amount)}`
        : "",
);

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Lương cơ bản", key: "base_salary" },
    { title: "Ngày công (TT/CC)", key: "work_days" },
    { title: "OT", key: "overtime_minutes" },
    { title: "Thực nhận", key: "net_salary" },
];

function formatMoney(value) {
    if (value === null || value === undefined) return "—";
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await payrollService.show(route.params.id);
        payroll.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không thể tải chi tiết bảng lương.";
    } finally {
        loading.value = false;
    }
}

const payslipDialog = ref(false);
const selectedDetail = ref(null);

const actions = [
    {
        icon: "mdi-file-document-outline",
        tooltip: "Xem phiếu lương",
        color: "primary",
        onClick: (item) => {
            selectedDetail.value = item;
            payslipDialog.value = true;
        },
    },
];

async function exportExcel() {
    const XLSX = await import("xlsx");
    const rows = (payroll.value?.details ?? []).map((d) => ({
        "Mã NV": d.employee?.code,
        "Họ tên": d.employee?.full_name,
        "Lương cơ bản": d.base_salary,
        "Ngày công chuẩn": d.standard_work_days,
        "Ngày công thực tế": d.actual_work_days,
        "OT (phút)": d.overtime_minutes,
        "Khấu trừ nghỉ không lương": d.unpaid_leave_deduction,
        "Bảo hiểm": d.insurance_amount,
        "Lương gộp": d.gross_salary,
        "Thuế TNCN": d.personal_income_tax,
        "Thực nhận": d.net_salary,
    }));
    const worksheet = XLSX.utils.json_to_sheet(rows);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, "BangLuong");
    XLSX.writeFile(
        workbook,
        `bang-luong-${payroll.value.period_month}-${payroll.value.period_year}.xlsx`,
    );
}

onMounted(loadData);
</script>
