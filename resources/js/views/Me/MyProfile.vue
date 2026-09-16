<template>
    <div>
        <PageHeader
            title="Hồ sơ của tôi"
            subtitle="Thông tin cá nhân, hợp đồng, tài liệu và ca làm việc của bạn."
        />

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
            <!-- Thẻ hồ sơ đầu trang -->
            <v-sheet
                class="border rounded-lg pa-5 mb-4 glass-panel profile-hero"
                color="transparent"
            >
                <div class="d-flex align-center flex-wrap ga-4">
                    <v-avatar size="80" color="surface-variant">
                        <v-img
                            v-if="employee.avatar_url"
                            :src="employee.avatar_url"
                        />
                        <v-icon v-else icon="mdi-account" size="40" />
                    </v-avatar>
                    <div class="flex-grow-1">
                        <div class="text-h6 font-weight-bold">
                            {{ employee.full_name }}
                        </div>
                        <div class="text-body-2" style="opacity: 0.75">
                            {{ employee.position?.name ?? "Chưa xếp chức vụ" }}
                            <template v-if="employee.department">
                                — {{ employee.department.name }}
                            </template>
                        </div>
                        <div class="d-flex align-center ga-2 mt-2 flex-wrap">
                            <StatusChip
                                :status="employee.employment_status"
                                :map="EMPLOYMENT_STATUS_MAP"
                            />
                            <v-chip
                                size="small"
                                variant="tonal"
                                color="default"
                            >
                                {{ employee.code }}
                            </v-chip>
                        </div>
                    </div>
                </div>
            </v-sheet>

            <!-- Thẻ thông tin nhanh -->
            <StatCards :stats="quickStats" />

            <v-sheet
                class="border rounded-lg mb-4 mt-4 glass-panel"
                color="transparent"
            >
                <v-tabs v-model="tab">
                    <v-tab value="info">Thông tin cá nhân</v-tab>
                    <v-tab value="contracts">Hợp đồng</v-tab>
                    <v-tab value="documents">Tài liệu</v-tab>
                    <v-tab value="shifts">Ca làm việc</v-tab>
                    <v-tab value="transfers">Luân chuyển</v-tab>
                    <v-tab value="payslips">Phiếu lương</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="info">
                    <MyProfileInfoTab
                        :employee="employee"
                        @updated="employee = $event"
                    />
                </v-window-item>

                <v-window-item value="contracts">
                    <MyProfileContractsTab
                        v-if="tabsOpened.contracts"
                        @preview="openPreview"
                    />
                </v-window-item>

                <v-window-item value="documents">
                    <MyProfileDocumentsTab
                        v-if="tabsOpened.documents"
                        @preview="openPreview"
                    />
                </v-window-item>

                <v-window-item value="shifts">
                    <MyProfileShiftsTab v-if="tabsOpened.shifts" />
                </v-window-item>

                <v-window-item value="transfers">
                    <MyProfileTransfersTab v-if="tabsOpened.transfers" />
                </v-window-item>

                <v-window-item value="payslips">
                    <MyProfilePayslipsTab v-if="tabsOpened.payslips" />
                </v-window-item>
            </v-window>
        </template>

        <div v-else-if="loading" class="d-flex justify-center py-10">
            <v-progress-circular indeterminate size="32" />
        </div>

        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />
    </div>
</template>

<script setup>
// Trang tự phục vụ (self-service) — CHÍNH nhân viên xem hồ sơ/hợp đồng/tài
// liệu/ca làm việc của MÌNH, dựng riêng thay vì dùng chung EmployeeDetail.vue
// (khác EmployeeDetail ở 2 điểm): (1) gọi các endpoint /employees/me/... —
// không phụ thuộc employee.view/shift.view (role Employee không có 2 mã
// quyền này, xem CODE_MAP mục 8/14); (2) gần như chỉ đọc — chỉ 2 việc tự làm
// được: sửa thông tin LIÊN HỆ và tự tải tài liệu cá nhân lên.
//
// Nội dung từng tab tách thành component riêng (giống EmployeeDetail.vue) —
// file này chỉ còn giữ: tải hồ sơ chính, thẻ hero + StatCards đầu trang,
// khung v-tabs/v-window, và FilePreviewDialog dùng CHUNG cho tab Hợp đồng/
// Tài liệu.
import { computed, onMounted, reactive, ref, watch } from "vue";
import employeeService from "../../services/employeeService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import MyProfileInfoTab from "./MyProfileInfoTab.vue";
import MyProfileContractsTab from "./MyProfileContractsTab.vue";
import MyProfileDocumentsTab from "./MyProfileDocumentsTab.vue";
import MyProfileShiftsTab from "./MyProfileShiftsTab.vue";
import MyProfileTransfersTab from "./MyProfileTransfersTab.vue";
import MyProfilePayslipsTab from "./MyProfilePayslipsTab.vue";

const EMPLOYMENT_STATUS_MAP = {
    probation: { label: "Thử việc", color: "warning" },
    active: { label: "Đang làm việc", color: "success" },
    resigned: { label: "Đã nghỉ việc", color: "default" },
    terminated: { label: "Đã chấm dứt HĐ", color: "error" },
};

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

// "2 năm 3 tháng" tính từ ngày vào làm tới hôm nay — chỉ hiển thị, không gửi
// lên API nào nên không cần chính xác tuyệt đối theo lịch âm/dương.
function formatTenure(hireDate) {
    if (!hireDate) {
        return "—";
    }
    const start = new Date(hireDate);
    const now = new Date();
    let months =
        (now.getFullYear() - start.getFullYear()) * 12 +
        (now.getMonth() - start.getMonth());
    if (now.getDate() < start.getDate()) {
        months -= 1;
    }
    if (months < 0) {
        return "—";
    }
    const years = Math.floor(months / 12);
    const remMonths = months % 12;
    if (years === 0) {
        return `${remMonths} tháng`;
    }
    if (remMonths === 0) {
        return `${years} năm`;
    }
    return `${years} năm ${remMonths} tháng`;
}

const employee = ref(null);
const loading = ref(true);
const loadError = ref("");
const tab = ref("info");

const quickStats = computed(() => {
    if (!employee.value) {
        return [];
    }
    const e = employee.value;
    return [
        {
            label: "Thâm niên",
            value: formatTenure(e.hire_date),
            color: "primary",
            icon: "mdi-clock-star-four-points-outline",
        },
        {
            label: "Ngày vào làm",
            value: formatDate(e.hire_date) ?? "—",
            color: "info",
            icon: "mdi-calendar-check-outline",
        },
        {
            label: "Chức vụ",
            value: e.position?.name ?? "—",
            color: "success",
            icon: "mdi-badge-account-outline",
        },
        {
            label: "Quản lý trực tiếp",
            value: e.manager?.full_name ?? "Không có",
            color: "warning",
            icon: "mdi-account-supervisor-outline",
        },
    ];
});

async function loadProfile() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await employeeService.me();
        employee.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.status === 404
                ? "Tài khoản này chưa được liên kết với hồ sơ nhân viên nào. Liên hệ phòng Nhân sự để được hỗ trợ."
                : "Không thể tải hồ sơ cá nhân, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

// Mỗi tab (trừ "Thông tin cá nhân" — hiện ngay vì chỉ đọc `employee` đã tải
// sẵn ở trên) chỉ mount component con lần đầu khi người dùng thật sự mở tab
// đó, giống EmployeeDetail.vue.
const tabsOpened = reactive({
    contracts: false,
    documents: false,
    shifts: false,
    transfers: false,
    payslips: false,
});

watch(tab, (value) => {
    if (value in tabsOpened) {
        tabsOpened[value] = true;
    }
});

const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });

function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

onMounted(() => {
    loadProfile();
});
</script>

<style scoped>
.profile-hero {
    background:
        radial-gradient(
            circle at 8% 0%,
            rgba(117, 117, 219, 0.16),
            transparent 22rem
        ),
        rgba(var(--v-theme-surface), 0.72);
}
</style>
