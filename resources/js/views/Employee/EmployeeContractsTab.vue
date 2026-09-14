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

                            <v-btn
                                icon="mdi-download-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                :loading="downloadingId === contract.id"
                                @click="downloadContract(contract)"
                            >
                                <v-icon icon="mdi-download-outline" />
                                <v-tooltip activator="parent" location="top">
                                    Tải file
                                </v-tooltip>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>
    </div>
</template>

<script setup>
// Tab "Hợp đồng" của EmployeeDetail.vue — tách riêng theo yêu cầu dễ bảo
// trì. Tự tải dữ liệu của chính nó ngay khi mount (component cha chỉ mount
// component này lần đầu khi người dùng thật sự mở tab, xem EmployeeDetail.vue).
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import StatusChip from "../../components/common/StatusChip.vue";

const props = defineProps({
    employeeId: {
        type: [String, Number],
        required: true,
    },
});

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
const downloadingId = ref(null);

async function loadContracts() {
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.contracts(props.employeeId);
        contracts.value = response.data.data;
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

async function downloadContract(contract) {
    downloadingId.value = contract.id;
    contractsError.value = "";
    try {
        // download_url trỏ tới route yêu cầu auth:api — dùng axios (tự gắn
        // Authorization header qua interceptor ở bootstrap.js) thay vì thẻ <a>
        // trần, vì thẻ <a> không gửi kèm header nên sẽ bị 401.
        const response = await window.axios.get(contract.download_url, {
            responseType: "blob",
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.download = `${contract.contract_number}.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải file hợp đồng.";
    } finally {
        downloadingId.value = null;
    }
}

onMounted(() => {
    loadContracts();
});
</script>
