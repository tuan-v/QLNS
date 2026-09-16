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
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="contractsLoading">
                        <td colspan="7" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!contracts.length">
                        <td
                            colspan="7"
                            class="text-center py-6"
                            style="opacity: 0.6"
                        >
                            Chưa có hợp đồng nào.
                        </td>
                    </tr>
                    <tr v-for="contract in contracts" v-else :key="contract.id">
                        <td>{{ contract.contract_number }}</td>
                        <td>
                            {{
                                CONTRACT_TYPE_MAP[contract.contract_type] ??
                                contract.contract_type
                            }}
                        </td>
                        <td>{{ formatDate(contract.start_date) }}</td>
                        <td>{{ formatDate(contract.end_date) ?? "—" }}</td>
                        <td>{{ formatCurrency(contract.agreed_salary) }}</td>
                        <td>
                            <StatusChip
                                :status="contract.status"
                                :map="CONTRACT_STATUS_MAP"
                            />
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-center ga-2">
                                <!-- Xem chi tiết BẢN GHI hợp đồng (Ngày ký/Lương
                                     đóng BH không có cột riêng trong bảng) —
                                     cùng dialog đã dùng ở EmployeeContractsTab.vue
                                     phía HR, chỉ khác nguồn dữ liệu (đây là API
                                     .../me/contracts, chỉ đọc hợp đồng của CHÍNH
                                     mình, không xem được của người khác). -->
                                <v-btn
                                    icon="mdi-information-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    @click="openDetailDialog(contract)"
                                >
                                    <v-icon icon="mdi-information-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xem chi tiết</v-tooltip
                                    >
                                </v-btn>

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
                                    <v-tooltip
                                        activator="parent"
                                        location="top"
                                    >
                                        Tải file
                                    </v-tooltip>
                                </v-btn>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <!-- Dialog CHỈ ĐỌC — cùng bố cục EmployeeContractsTab.vue phía HR. -->
        <v-dialog v-model="detailDialog" max-width="480">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Chi tiết hợp đồng {{ detailContract?.contract_number }}
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.5rem"
                >
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Loại hợp đồng</span>
                        <strong>{{
                            CONTRACT_TYPE_MAP[detailContract?.contract_type] ??
                            detailContract?.contract_type
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày ký</span>
                        <strong>{{
                            formatDate(detailContract?.signed_at) ?? "—"
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày bắt đầu</span>
                        <strong>{{
                            formatDate(detailContract?.start_date)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Ngày kết thúc</span>
                        <strong>{{
                            formatDate(detailContract?.end_date) ?? "—"
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis"
                            >Lương thỏa thuận</span
                        >
                        <strong>{{
                            formatCurrency(detailContract?.agreed_salary)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between">
                        <span class="text-medium-emphasis">Lương đóng BH</span>
                        <strong>{{
                            formatCurrency(detailContract?.insurance_salary)
                        }}</strong>
                    </div>
                    <div class="d-flex justify-space-between align-center">
                        <span class="text-medium-emphasis">Trạng thái</span>
                        <StatusChip
                            :status="detailContract?.status"
                            :map="CONTRACT_STATUS_MAP"
                        />
                    </div>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="detailDialog = false">
                        Đóng
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Tab "Hợp đồng" của MyProfile.vue — tách riêng theo yêu cầu dễ bảo trì.
// Chỉ đọc (Hợp đồng lao động do HR/Quản lý tạo) — nhưng vẫn cho Xem chi
// tiết/Tải file như HR xem, chỉ khác là API .../me/contracts chỉ trả về
// đúng hợp đồng của CHÍNH người đang đăng nhập.
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import StatusChip from "../../components/common/StatusChip.vue";

defineEmits(["preview"]);

const CONTRACT_STATUS_MAP = {
    active: { label: "Đang áp dụng", color: "success" },
    pending: { label: "Chưa hiệu lực", color: "warning" },
    expired: { label: "Hết hạn", color: "default" },
    terminated: { label: "Đã chấm dứt", color: "error" },
};

const CONTRACT_TYPE_MAP = {
    thu_viec: "Thử việc",
    chinh_thuc: "Chính thức",
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
        const response = await employeeService.myContracts();
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
        // trần, cùng lý do như EmployeeContractsTab.vue phía HR.
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

const detailDialog = ref(false);
const detailContract = ref(null);

function openDetailDialog(contract) {
    detailContract.value = contract;
    detailDialog.value = true;
}

onMounted(() => {
    loadContracts();
});
</script>
