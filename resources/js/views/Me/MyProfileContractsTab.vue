<template>
    <div>
        <v-alert
            v-if="contractsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ contractsError }}
        </v-alert>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Số hợp đồng</th>
                        <th>Loại HĐ</th>
                        <th>Bắt đầu</th>
                        <th>Kết thúc</th>
                        <th>Lương thỏa thuận</th>
                        <th>Trạng thái</th>
                        <th class="text-end">File</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="contractsLoading">
                        <td colspan="7" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!contracts.length">
                        <td colspan="7" class="text-center py-6" style="opacity: 0.6">
                            Chưa có hợp đồng nào.
                        </td>
                    </tr>
                    <tr v-for="contract in contracts" v-else :key="contract.id">
                        <td>{{ contract.contract_number }}</td>
                        <td>{{ contract.contract_type }}</td>
                        <td>{{ formatDate(contract.start_date) }}</td>
                        <td>{{ formatDate(contract.end_date) ?? "—" }}</td>
                        <td>{{ formatCurrency(contract.agreed_salary) }}</td>
                        <td>
                            <StatusChip
                                :status="contract.status"
                                :map="CONTRACT_STATUS_MAP"
                            />
                        </td>
                        <td class="text-end">
                            <v-btn
                                icon="mdi-eye-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                @click="
                                    $emit(
                                        'preview',
                                        contract.download_url,
                                        contract.contract_number + '.pdf',
                                    )
                                "
                            >
                                <v-icon icon="mdi-eye-outline" />
                                <v-tooltip activator="parent" location="top"
                                    >Xem trước</v-tooltip
                                >
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>
    </div>
</template>

<script setup>
// Tab "Hợp đồng" của MyProfile.vue — tách riêng theo yêu cầu dễ bảo trì.
// Chỉ đọc (Hợp đồng lao động do HR/Quản lý tạo, không có nút Tải riêng ở
// đây — chỉ "Xem trước" qua FilePreviewDialog dùng chung ở component cha).
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import StatusChip from "../../components/common/StatusChip.vue";

defineEmits(["preview"]);

const CONTRACT_STATUS_MAP = {
    active: { label: "Còn hiệu lực", color: "success" },
    expired: { label: "Hết hạn", color: "default" },
    terminated: { label: "Đã chấm dứt", color: "error" },
};

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatCurrency(value) {
    if (value === null || value === undefined) {
        return "—";
    }
    return new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(value);
}

const contracts = ref([]);
const contractsLoading = ref(false);
const contractsError = ref("");

async function loadContracts() {
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.myContracts();
        contracts.value = response.data.data;
    } catch (e) {
        contractsError.value = e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

onMounted(() => {
    loadContracts();
});
</script>
