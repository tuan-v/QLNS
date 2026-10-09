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
                    <v-tab value="profile" prepend-icon="mdi-account-outline">Sơ yếu lý lịch</v-tab>
                    <v-tab value="contracts" prepend-icon="mdi-file-document-outline">Hợp đồng</v-tab>
                    <v-tab value="documents" prepend-icon="mdi-folder-outline">Tài liệu</v-tab>
                    <v-tab value="transfers" prepend-icon="mdi-swap-horizontal">Luân chuyển</v-tab>
                    <v-tab value="shift_assignments" prepend-icon="mdi-calendar-clock-outline">Ca làm việc</v-tab>
                    <v-tab value="attendance" prepend-icon="mdi-calendar-check-outline">Chấm công</v-tab>
                    <v-tab value="payroll" prepend-icon="mdi-cash-multiple">Lương / Phép</v-tab>
                    <v-tab v-if="canUpdate" value="bank" prepend-icon="mdi-bank-outline">Tài khoản ngân hàng</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="profile">
                    <EmployeeProfileTab
                        :employee="employee"
                        :can-update="canUpdate"
                        @account-created="loadEmployee"
                    />
                </v-window-item>

                <v-window-item value="contracts">
                    <EmployeeContractsTab
                        v-if="tabsOpened.contracts"
                        :employee-id="props.id"
                        :employment-status="employee.employment_status"
                        @preview="openPreview"
                        @changed="loadEmployee"
                    />
                </v-window-item>

                <v-window-item value="documents">
                    <EmployeeDocumentsTab
                        v-if="tabsOpened.documents"
                        :employee-id="props.id"
                        @preview="openPreview"
                    />
                </v-window-item>

                <v-window-item value="transfers">
                    <EmployeeTransfersTab
                        v-if="tabsOpened.transfers"
                        :employee-id="props.id"
                        :current-department-id="employee.department?.id"
                        @preview="openPreview"
                        @transferred="loadEmployee"
                    />
                </v-window-item>

                <v-window-item value="shift_assignments">
                    <EmployeeShiftAssignmentsTab
                        v-if="tabsOpened.shift_assignments"
                        :employee-id="props.id"
                        :can-update="canUpdate"
                    />
                </v-window-item>

                <v-window-item value="attendance">
                    <AttendanceHistoryPanel
                        v-if="tabsOpened.attendance"
                        :employee-id="props.id"
                        :read-only="false"
                    />
                </v-window-item>

                <v-window-item value="payroll">
                    <EmployeeSalaryLeaveTab
                        v-if="tabsOpened.payroll"
                        :employee-id="props.id"
                    />
                </v-window-item>

                <v-window-item v-if="canUpdate" value="bank">
                    <BankAccountsPanel
                        v-if="tabsOpened.bank"
                        :employee-id="Number(props.id)"
                        :employee-name="employee.full_name"
                    />
                </v-window-item>
            </v-window>
        </template>

        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />
    </div>
</template>

<script setup>
// Trang chi tiết nhân viên — chỉ còn giữ: tải hồ sơ chính, khung v-tabs/
// v-window, và FilePreviewDialog dùng CHUNG cho 3 tab (Hợp đồng/Tài liệu/
// Luân chuyển). Nội dung từng tab đã tách thành component riêng
// (EmployeeProfileTab.vue, EmployeeContractsTab.vue, EmployeeDocumentsTab.vue,
// EmployeeTransfersTab.vue, EmployeeShiftAssignmentsTab.vue) — file này
// trước đây gộp cả 7 tab, dài 1865 dòng, khó đọc/khó bảo trì.
import { onMounted, reactive, ref, watch, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import employeeService from "../../services/employeeService";
import { useAuthStore } from "../../stores/authStore";
import PageHeader from "../../components/common/PageHeader.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import AttendanceHistoryPanel from "../Attendance/AttendanceHistoryPanel.vue";
import EmployeeProfileTab from "./EmployeeProfileTab.vue";
import EmployeeContractsTab from "./EmployeeContractsTab.vue";
import EmployeeDocumentsTab from "./EmployeeDocumentsTab.vue";
import EmployeeTransfersTab from "./EmployeeTransfersTab.vue";
import EmployeeShiftAssignmentsTab from "./EmployeeShiftAssignmentsTab.vue";
import BankAccountsPanel from "../../components/employee/BankAccountsPanel.vue";
import EmployeeSalaryLeaveTab from "./EmployeeSalaryLeaveTab.vue";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const props = defineProps({
    id: {
        type: String,
        required: true,
    },
});

const router = useRouter();
const route = useRoute();
const auth = useAuthStore();

const canUpdate = computed(() => auth.permissions.includes("employee.update"));

const employee = ref(null);
const loadError = ref("");
// ?tab=... (vd thông báo "Tài khoản ngân hàng cần xác nhận") mở thẳng đúng tab.
const VALID_TABS = ["profile", "contracts", "documents", "transfers", "shift_assignments", "attendance", "payroll", "bank"];
const tab = ref(VALID_TABS.includes(route.query.tab) ? route.query.tab : "profile");

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

// Mỗi tab (trừ "Sơ yếu lý lịch" — hiện ngay vì chỉ đọc `employee` đã tải sẵn
// ở trên) chỉ MOUNT component con lần đầu khi người dùng thật sự mở tab đó
// — tránh gọi API thừa nếu họ chỉ xem 1-2 tab rồi rời trang. `v-window`
// không unmount lại tab đã mở (chỉ ẩn/hiện qua CSS) nên mỗi component con
// chỉ cần tự `onMounted` gọi API của nó đúng 1 lần, không cần tự giữ thêm
// cờ "đã tải" như bản gộp chung trước đây.
const tabsOpened = reactive({
    contracts: false,
    documents: false,
    transfers: false,
    shift_assignments: false,
    attendance: false,
    payroll: false,
    bank: false,
});
watch(tab, (value) => {
    if (value in tabsOpened) {
        tabsOpened[value] = true;
    }
});
// Vào thẳng bằng ?tab= thì watch(tab) không chạy lần đầu — tự đánh dấu đã mở.
if (tab.value in tabsOpened) {
    tabsOpened[tab.value] = true;
}
watch(
    () => route.query.tab,
    (value) => {
        if (VALID_TABS.includes(value)) {
            tab.value = value;
        }
    },
);

// FilePreviewDialog dùng chung cho tab Hợp đồng/Tài liệu/Luân chuyển — mỗi
// tab con chỉ emit sự kiện `preview`, không tự dựng dialog riêng.
const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });
function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

useRealtimeRefresh(loadEmployee, {
    shared: [{ resource: "employees", permission: "employee.view" }],
});

onMounted(() => {
    loadEmployee();
});

// Đang xem nhân viên A mà bấm link sang nhân viên B (vd thông báo) thì Vue Router dùng
// lại CHÍNH component này, onMounted không chạy lại -> phải tự tải lại. Xóa dữ liệu cũ
// trước để các tab (nằm trong v-if="employee") dựng lại theo nhân viên mới.
watch(
    () => props.id,
    (id, oldId) => {
        if (id !== oldId) {
            employee.value = null;
            loadEmployee();
        }
    },
);
</script>
