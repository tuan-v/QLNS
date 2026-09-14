<template>
    <div>
        <PageHeader
            title="Bảng lương"
            subtitle="Quản lý bảng lương theo từng kỳ (tháng/năm)."
        >
            <template #actions>
                <v-btn
                    v-if="canManage"
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="generateDialog = true"
                >
                    Tạo bảng lương
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
            :items="payrolls"
            :loading="loading"
            :actions="actions"
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
            </template>
            <template #item.period="{ item }">
                Tháng {{ item.period_month }}/{{ item.period_year }}
            </template>
            <template #item.total_payroll_amount="{ item }">
                {{ formatMoney(item.total_payroll_amount) }}
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="PAYROLL_STATUS_MAP" />
            </template>
        </DataTable>

        <PayrollGenerateDialog
            v-model="generateDialog"
            @generated="fetchData"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import { useAuthStore } from "../../stores/authStore";
import payrollService from "../../services/payrollService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import PayrollGenerateDialog from "./PayrollGenerateDialog.vue";
import { useToastStore } from "../../stores/useToastStore";

const PAYROLL_STATUS_MAP = {
    processing: { label: "Đang xử lý", color: "warning" },
    closed: { label: "Đã chốt", color: "info" },
    paid: { label: "Đã trả", color: "success" },
};

const auth = useAuthStore();
const toast = useToastStore();

const payrolls = ref([]);
const loading = ref(false);
const loadError = ref("");
const generateDialog = ref(false);

const canManage = computed(() => auth.permissions.includes("payroll.manage"));

const headers = [
    { title: "#", key: "index", sortable: false, width: 56 },
    { title: "Kỳ lương", key: "period" },
    { title: "Tổng chi phí lương", key: "total_payroll_amount" },
    { title: "Trạng thái", key: "status", width: 150 },
];

function formatMoney(value) {
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

async function fetchData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await payrollService.list();
        payrolls.value = response.data.data;
    } catch (e) {
        payrolls.value = [];
        loadError.value =
            e.response?.data?.message ??
            "Không thể kết nối máy chủ, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

const actions = computed(() => [
    {
        icon: "mdi-lock-check-outline",
        tooltip: "Chốt bảng lương",
        color: "warning",
        hidden: !canManage.value,
        disabled: (item) => item.status !== "processing",
        confirm: {
            title: "Chốt bảng lương",
            message: () =>
                "Sau khi chốt sẽ không thể sửa lại số liệu của kỳ lương này. Tiếp tục?",
            confirmText: "Chốt",
        },
        onClick: async (item) => {
            await payrollService.close(item.id);
            await fetchData();
            toast.success("Đã chốt bảng lương.");
        },
    },
    {
        icon: "mdi-cash-check",
        tooltip: "Đánh dấu đã trả",
        color: "success",
        hidden: !canManage.value,
        disabled: (item) => item.status !== "closed",
        confirm: {
            title: "Đánh dấu đã trả lương",
            message: () => "Xác nhận đã thanh toán lương cho kỳ này?",
            confirmText: "Xác nhận",
        },
        onClick: async (item) => {
            await payrollService.markAsPaid(item.id);
            await fetchData();
            toast.success("Đã đánh dấu đã trả lương.");
        },
    },
]);

onMounted(fetchData);
</script>
