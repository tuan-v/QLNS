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
                    <v-tab value="documents">Tài liệu</v-tab>
                    <v-tab value="transfers">Luân chuyển</v-tab>
                    <v-tab value="shift_assignments">Ca làm việc</v-tab>
                    <v-tab value="attendance">Chấm công</v-tab>
                    <v-tab value="payroll">Lương / Phép</v-tab>
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
                        @preview="openPreview"
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
                    <v-alert type="info" variant="tonal" icon="mdi-information-outline">
                        Chưa triển khai — Lương thuộc Phase 4 (Ngày 46+), Nghỉ
                        phép thuộc Phase 3 (Ngày 36+) theo kế hoạch dự án.
                    </v-alert>
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
import { useRouter } from "vue-router";
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

const props = defineProps({
    id: {
        type: String,
        required: true,
    },
});

const router = useRouter();
const auth = useAuthStore();

const canUpdate = computed(() => auth.permissions.includes("employee.update"));

const employee = ref(null);
const loadError = ref("");
const tab = ref("profile");

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
});
watch(tab, (value) => {
    if (value in tabsOpened) {
        tabsOpened[value] = true;
    }
});

// FilePreviewDialog dùng chung cho tab Hợp đồng/Tài liệu/Luân chuyển — mỗi
// tab con chỉ emit sự kiện `preview`, không tự dựng dialog riêng.
const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });
function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

onMounted(() => {
    loadEmployee();
});
</script>
