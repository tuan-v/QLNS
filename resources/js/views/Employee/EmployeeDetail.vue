<template>
    <div>
        <PageHeader
            :title="employee?.full_name ?? 'Chi tiết nhân viên'"
            :subtitle="employee?.code ?? ''"
        >
            <template #actions>
                <v-btn
                    variant="tonal"
                    prepend-icon="mdi-arrow-left"
                    @click="router.push({ name: 'employees' })"
                >
                    Quay lại
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

        <template v-if="employee">
            <v-sheet class="border rounded-lg mb-4 glass-panel" color="transparent">
                <v-tabs v-model="tab">
                    <v-tab value="profile">Sơ yếu lý lịch</v-tab>
                    <v-tab value="contracts">Hợp đồng</v-tab>
                    <v-tab value="payroll">Lương / Phép</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="profile">
                    <v-sheet class="border rounded-lg pa-5 glass-panel" color="transparent">
                        <div class="d-flex align-center ga-4 mb-5">
                            <v-avatar size="72" color="surface-variant">
                                <v-img v-if="employee.avatar_url" :src="employee.avatar_url" />
                                <v-icon v-else icon="mdi-account" size="36" />
                            </v-avatar>
                            <div>
                                <div class="text-h6 font-weight-bold">
                                    {{ employee.full_name }}
                                </div>
                                <StatusChip
                                    :status="employee.employment_status"
                                    :map="EMPLOYMENT_STATUS_MAP"
                                />
                            </div>
                        </div>

                        <v-row dense>
                            <v-col
                                v-for="field in profileFields"
                                :key="field.label"
                                cols="12"
                                sm="6"
                                md="4"
                            >
                                <div class="text-caption" style="opacity: 0.6">
                                    {{ field.label }}
                                </div>
                                <div class="text-body-2 font-weight-medium">
                                    {{ field.value ?? "—" }}
                                </div>
                            </v-col>
                        </v-row>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="contracts">
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
                </v-window-item>

                <v-window-item value="payroll">
                    <v-alert type="info" variant="tonal" icon="mdi-information-outline">
                        Chưa triển khai — Lương thuộc Phase 4 (Ngày 46+), Nghỉ phép
                        thuộc Phase 3 (Ngày 36+) theo kế hoạch dự án.
                    </v-alert>
                </v-window-item>
            </v-window>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from "vue";
import { useRouter } from "vue-router";
import employeeService from "../../services/employeeService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";

const props = defineProps({
    id: {
        type: String,
        required: true,
    },
});

const router = useRouter();

const EMPLOYMENT_STATUS_MAP = {
    probation: { label: "Thử việc", color: "warning" },
    active: { label: "Đang làm việc", color: "success" },
    resigned: { label: "Đã nghỉ việc", color: "default" },
    terminated: { label: "Đã chấm dứt HĐ", color: "error" },
};

const GENDER_MAP = {
    male: "Nam",
    female: "Nữ",
    other: "Khác",
};

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

// Gộp 3 phần (chi tiết, Xã, Tỉnh) thành 1 dòng hiển thị — trả về null (không
// phải chuỗi rỗng) khi không có gì để field.value ?? "—" ở template hiện
// đúng dấu gạch ngang thay vì để trống trơn.
function formatAddress(e) {
    const parts = [e.address_detail, e.commune?.name, e.province?.name].filter(
        Boolean,
    );
    return parts.length ? parts.join(", ") : null;
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

/* ------------------------------- Tab 1: Hồ sơ ------------------------------ */

const employee = ref(null);
const loadError = ref("");
const tab = ref("profile");

const profileFields = computed(() => {
    if (!employee.value) {
        return [];
    }
    const e = employee.value;
    return [
        { label: "Ngày sinh", value: formatDate(e.date_of_birth) },
        { label: "Giới tính", value: GENDER_MAP[e.gender] },
        { label: "Điện thoại", value: e.phone },
        { label: "Email công ty", value: e.company_email },
        { label: "Email cá nhân", value: e.personal_email },
        { label: "CCCD", value: e.cccd },
        { label: "Mã số thuế cá nhân", value: e.personal_tax_code },
        { label: "Địa chỉ", value: formatAddress(e) },
        { label: "Phòng ban", value: e.department?.name },
        { label: "Chức vụ", value: e.position?.name },
        { label: "Quản lý trực tiếp", value: e.manager?.full_name },
        { label: "Ngày vào làm", value: formatDate(e.hire_date) },
    ];
});

async function loadEmployee() {
    loadError.value = "";
    try {
        const response = await employeeService.get(props.id);
        employee.value = response.data.data;
    } catch (e) {
        employee.value = null;
        loadError.value =
            e.response?.data?.message ?? "Không thể tải thông tin nhân viên.";
    }
}

/* ----------------------------- Tab 2: Hợp đồng ----------------------------- */

const contracts = ref([]);
const contractsLoaded = ref(false);
const contractsLoading = ref(false);
const contractsError = ref("");
const downloadingId = ref(null);

async function loadContracts() {
    if (contractsLoaded.value) {
        return;
    }
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.contracts(props.id);
        contracts.value = response.data.data;
        contractsLoaded.value = true;
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

// Chỉ gọi API hợp đồng khi người dùng thật sự mở Tab 2, tránh gọi thừa nếu
// họ chỉ xem Sơ yếu lý lịch rồi rời trang — giống tinh thần tối ưu N+1 ở
// Ngày 25, áp dụng ở mức "đừng gọi API khi chưa cần".
watch(tab, (value) => {
    if (value === "contracts") {
        loadContracts();
    }
});

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
    loadEmployee();
});
</script>
