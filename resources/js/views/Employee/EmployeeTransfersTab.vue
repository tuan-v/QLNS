<template>
    <div>
        <v-alert
            v-if="transfersError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ transfersError }}
        </v-alert>

        <div class="d-flex justify-end mb-3">
            <v-btn
                color="primary"
                variant="flat"
                prepend-icon="mdi-transfer"
                @click="openTransferDialog"
            >
                Tạo luân chuyển
            </v-btn>
        </div>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Từ phòng ban</th>
                        <th>Đến phòng ban</th>
                        <th>Chức vụ mới</th>
                        <th>Ngày hiệu lực</th>
                        <th>Lý do</th>
                        <th>Người duyệt</th>
                        <th class="text-end">Quyết định</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="transfersLoading">
                        <td colspan="7" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!transfers.length">
                        <td colspan="7" class="text-center py-6" style="opacity: 0.6">
                            Chưa có lượt luân chuyển nào.
                        </td>
                    </tr>
                    <tr v-for="t in transfers" v-else :key="t.id">
                        <td>{{ t.from_department?.name ?? "—" }}</td>
                        <td>{{ t.to_department?.name ?? "—" }}</td>
                        <td>{{ t.new_position?.name ?? "—" }}</td>
                        <td>{{ formatDate(t.effective_date) }}</td>
                        <td>{{ t.reason ?? "—" }}</td>
                        <td>{{ t.approver ?? "—" }}</td>
                        <td class="text-end">
                            <v-btn
                                icon="mdi-eye-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                @click="
                                    $emit(
                                        'preview',
                                        t.decision_file_url,
                                        'quyet-dinh' + t.id + '.pdf',
                                    )
                                "
                            >
                                <v-icon icon="mdi-eye-outline" />
                                <v-tooltip activator="parent" location="top"
                                    >Xem trước</v-tooltip
                                >
                            </v-btn>

                            <v-btn
                                v-if="t.decision_file_url"
                                icon="mdi-download-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                :loading="downloadingTransferId === t.id"
                                @click="downloadTransferDecision(t)"
                            >
                                <v-icon icon="mdi-download-outline" />
                                <v-tooltip activator="parent" location="top">
                                    Tải quyết định
                                </v-tooltip>
                            </v-btn>
                            <span v-else style="opacity: 0.4">—</span>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <v-dialog v-model="transferDialog" max-width="520" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Tạo luân chuyển
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Phòng ban mới <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            :model-value="transferForm.to_department_id"
                            :items="transferDepartmentOptions"
                            :error-messages="transferErrors.to_department_id"
                            clearable
                            @update:model-value="onTransferDepartmentChange"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Chức vụ mới
                        </div>
                        <SearchSelect
                            v-model="transferForm.new_position_id"
                            :items="transferPositionOptions"
                            :error-messages="transferErrors.new_position_id"
                            clearable
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Quản lý mới
                        </div>
                        <SearchSelect
                            v-model="transferForm.new_manager_id"
                            :items="transferManagerOptions"
                            :error-messages="transferErrors.new_manager_id"
                            clearable
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày hiệu lực <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="transferForm.effective_date"
                            :error-messages="transferErrors.effective_date"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">Lý do</div>
                        <v-textarea
                            v-model="transferForm.reason"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            rows="2"
                            no-resize
                            :error-messages="transferErrors.reason"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Quyết định điều động (PDF, không bắt buộc)
                        </div>
                        <v-file-input
                            v-model="transferForm.decision_file"
                            placeholder="Chọn tệp PDF (tối đa 10MB)"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            accept=".pdf"
                            :error-messages="transferErrors.decision_file"
                        />
                    </div>

                    <v-alert
                        v-if="transferGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ transferGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="transferSubmitting"
                        @click="closeTransferDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="transferSubmitting"
                        @click="submitTransfer"
                    >
                        Xác nhận luân chuyển
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Tab "Luân chuyển" của EmployeeDetail.vue — tách riêng theo yêu cầu dễ bảo
// trì. Tự tải danh sách luân chuyển ngay khi mount; danh sách Phòng ban/
// Chức vụ/Quản lý cho dialog Tạo chỉ tải khi thật sự mở dialog (hiếm dùng
// hơn nhiều so với xem tab).
import { computed, onMounted, reactive, ref } from "vue";
import employeeService from "../../services/employeeService";
import departmentService from "../../services/departmentService";
import positionService from "../../services/positionService";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    employeeId: {
        type: [String, Number],
        required: true,
    },
    // Phòng ban hiện tại của nhân viên — loại khỏi dropdown "Phòng ban mới"
    // (backend cũng chặn 422 nếu chọn trùng, nhưng lọc sẵn ở đây đỡ 1 vòng
    // round-trip cho người dùng, xem EmployeeTransferService::create()).
    currentDepartmentId: {
        type: [String, Number],
        default: null,
    },
});

// `preview` mở FilePreviewDialog dùng chung ở EmployeeDetail.vue.
// `transferred` báo cho component cha tải lại `employee` — phòng ban/chức
// vụ/quản lý vừa đổi, tab Sơ yếu lý lịch phải khớp nếu người dùng quay lại.
const emit = defineEmits(["preview", "transferred"]);

const toast = useToastStore();

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

const transfers = ref([]);
const transfersLoading = ref(false);
const transfersError = ref("");
const downloadingTransferId = ref(null);

async function loadTransfers() {
    transfersLoading.value = true;
    transfersError.value = "";
    try {
        const response = await employeeService.transfers(props.employeeId);
        transfers.value = response.data.data;
    } catch (e) {
        transfersError.value =
            e.response?.data?.message ?? "Không thể tải lịch sử luân chuyển.";
    } finally {
        transfersLoading.value = false;
    }
}

async function downloadTransferDecision(t) {
    downloadingTransferId.value = t.id;
    transfersError.value = "";
    try {
        const response = await window.axios.get(t.decision_file_url, {
            responseType: "blob",
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.download = `quyet-dinh-${t.id}.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        transfersError.value =
            e.response?.data?.message ?? "Không thể tải quyết định điều động.";
    } finally {
        downloadingTransferId.value = null;
    }
}

/* ----------------------------- Dialog tạo luân chuyển ---------------------- */

const transferDialog = ref(false);
const transferForm = reactive({
    to_department_id: null,
    new_position_id: null,
    new_manager_id: null,
    effective_date: "",
    reason: "",
    decision_file: null,
});
const transferErrors = ref({});
const transferGeneralError = ref("");
const transferSubmitting = ref(false);

const allDepartments = ref([]);
const allPositionsForTransfer = ref([]);
const allManagersForTransfer = ref([]);
const transferOptionsLoaded = ref(false);

function flattenDepartments(nodes) {
    return nodes.flatMap((node) => [
        node,
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}

const transferDepartmentOptions = computed(() =>
    flattenDepartments(allDepartments.value)
        .filter((dept) => String(dept.id) !== String(props.currentDepartmentId))
        .map((dept) => ({
            title: dept.name,
            value: dept.id,
        })),
);

const transferPositionOptions = computed(() =>
    allPositionsForTransfer.value
        .filter(
            (position) =>
                !transferForm.to_department_id ||
                position.department_id === transferForm.to_department_id,
        )
        .map((position) => ({ title: position.name, value: position.id })),
);

const transferManagerOptions = computed(() =>
    allManagersForTransfer.value
        .filter((e) => String(e.id) !== String(props.employeeId))
        .map((e) => ({ title: `${e.full_name} (${e.code})`, value: e.id })),
);

// Đổi Phòng ban mới thì Chức vụ mới (thuộc phòng ban cũ) không còn hợp lệ —
// cùng lý do onDepartmentChange() của EmployeeForm.vue không dùng watch() chung.
function onTransferDepartmentChange(value) {
    transferForm.to_department_id = value;
    transferForm.new_position_id = null;
}

// Tải danh sách Phòng ban/Chức vụ/Quản lý chỉ khi thật sự mở dialog — hành
// động "tạo luân chuyển" hiếm khi dùng hơn nhiều so với xem tab, không đáng
// tải sẵn lúc mount.
async function loadTransferOptions() {
    if (transferOptionsLoaded.value) {
        return;
    }
    const [deptRes, posRes, empRes] = await Promise.all([
        departmentService.tree(),
        positionService.list({ per_page: 1000 }),
        employeeService.list({ per_page: 1000 }),
    ]);
    allDepartments.value = deptRes.data;
    allPositionsForTransfer.value = posRes.data.data;
    allManagersForTransfer.value = empRes.data.data;
    transferOptionsLoaded.value = true;
}

function openTransferDialog() {
    transferForm.to_department_id = null;
    transferForm.new_position_id = null;
    transferForm.new_manager_id = null;
    transferForm.effective_date = "";
    transferForm.reason = "";
    transferForm.decision_file = null;
    transferErrors.value = {};
    transferGeneralError.value = "";
    transferDialog.value = true;
    loadTransferOptions();
}

function closeTransferDialog() {
    transferDialog.value = false;
}

async function submitTransfer() {
    transferErrors.value = {};
    transferGeneralError.value = "";
    transferSubmitting.value = true;
    try {
        const formData = new FormData();
        formData.append("to_department_id", transferForm.to_department_id ?? "");
        if (transferForm.new_position_id) {
            formData.append("new_position_id", transferForm.new_position_id);
        }
        if (transferForm.new_manager_id) {
            formData.append("new_manager_id", transferForm.new_manager_id);
        }
        formData.append("effective_date", transferForm.effective_date ?? "");
        if (transferForm.reason) {
            formData.append("reason", transferForm.reason);
        }
        const file = Array.isArray(transferForm.decision_file)
            ? transferForm.decision_file[0]
            : transferForm.decision_file;
        if (file) {
            formData.append("decision_file", file);
        }

        const response = await employeeService.createTransfer(props.employeeId, formData);
        transfers.value = [response.data.data, ...transfers.value];
        toast.success("Đã tạo luân chuyển — hồ sơ nhân viên đã cập nhật.");
        closeTransferDialog();
        emit("transferred");
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            transferErrors.value = {
                to_department_id: data.errors.to_department_id?.[0],
                new_position_id: data.errors.new_position_id?.[0],
                new_manager_id: data.errors.new_manager_id?.[0],
                effective_date: data.errors.effective_date?.[0],
                reason: data.errors.reason?.[0],
                decision_file: data.errors.decision_file?.[0],
            };
        } else {
            transferGeneralError.value = data?.message ?? "Không thể tạo luân chuyển.";
        }
    } finally {
        transferSubmitting.value = false;
    }
}

onMounted(() => {
    loadTransfers();
});
</script>
