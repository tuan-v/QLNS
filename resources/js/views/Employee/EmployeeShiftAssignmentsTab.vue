<template>
    <div>
        <v-alert
            v-if="shiftAssignmentsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ shiftAssignmentsError }}
        </v-alert>

        <div class="d-flex justify-end mb-3">
            <v-btn
                v-if="canUpdate"
                color="primary"
                variant="flat"
                prepend-icon="mdi-timetable"
                @click="openAssignShiftDialog()"
            >
                Gán ca làm việc
            </v-btn>
        </div>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Ca làm việc</th>
                        <th>Ngày bắt đầu</th>
                        <th>Ngày kết thúc</th>
                        <th>Ngày trong tuần</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="shiftAssignmentsLoading">
                        <td colspan="6" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!shiftAssignments.length">
                        <td colspan="6" class="text-center py-6" style="opacity: 0.6">
                            Chưa gán ca làm việc nào.
                        </td>
                    </tr>
                    <tr v-for="a in shiftAssignments" v-else :key="a.id">
                        <td>
                            {{ a.work_shift?.name ?? "—" }}
                            <span style="opacity: 0.5">
                                ({{ a.work_shift?.code }})
                            </span>
                        </td>
                        <td>{{ formatDate(a.effective_from) }}</td>
                        <td>
                            {{ a.effective_to ? formatDate(a.effective_to) : "—" }}
                        </td>
                        <td>{{ formatWorkDays(a.work_days) }}</td>
                        <td>
                            <v-chip
                                size="small"
                                variant="tonal"
                                :color="a.status === 'active' ? 'success' : 'default'"
                            >
                                {{ a.status === "active" ? "Đang áp dụng" : "Đã kết thúc" }}
                            </v-chip>
                        </td>
                        <td class="text-end">
                            <v-btn
                                v-if="canUpdate"
                                icon="mdi-pencil-outline"
                                variant="tonal"
                                color="primary"
                                size="small"
                                rounded="lg"
                                class="me-1"
                                @click="openAssignShiftDialog(a)"
                            >
                                <v-icon icon="mdi-pencil-outline" />
                                <v-tooltip activator="parent" location="top">Sửa</v-tooltip>
                            </v-btn>
                            <v-btn
                                v-if="canUpdate"
                                icon="mdi-delete-outline"
                                variant="tonal"
                                color="error"
                                size="small"
                                rounded="lg"
                                :loading="deletingAssignmentId === a.id"
                                @click="deleteShiftAssignment(a)"
                            >
                                <v-icon icon="mdi-delete-outline" />
                                <v-tooltip activator="parent" location="top">Xóa</v-tooltip>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <v-dialog v-model="assignShiftDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ editingAssignment ? "Sửa ca làm việc" : "Gán ca làm việc" }}
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ca làm việc <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="assignShiftForm.work_shift_id"
                            :items="workShiftOptions"
                            :error-messages="assignShiftErrors.work_shift_id"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày bắt đầu áp dụng <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="assignShiftForm.effective_from"
                            :error-messages="assignShiftErrors.effective_from"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày làm việc trong tuần <span class="text-error">*</span>
                        </div>
                        <v-btn-toggle
                            v-model="assignShiftForm.work_days"
                            multiple
                            density="comfortable"
                            variant="outlined"
                            divided
                        >
                            <v-btn
                                v-for="day in WEEK_DAYS"
                                :key="day.value"
                                :value="day.value"
                                size="small"
                            >
                                {{ day.label }}
                            </v-btn>
                        </v-btn-toggle>
                        <div
                            v-if="assignShiftErrors.work_days"
                            class="text-error text-caption mt-1"
                        >
                            {{ assignShiftErrors.work_days }}
                        </div>
                    </div>

                    <v-alert
                        v-if="assignShiftGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ assignShiftGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="assignShiftSubmitting"
                        @click="closeAssignShiftDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="assignShiftSubmitting"
                        @click="submitAssignShift"
                    >
                        {{ editingAssignment ? "Lưu thay đổi" : "Gán ca" }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Tab "Ca làm việc" của EmployeeDetail.vue — tách riêng theo yêu cầu dễ bảo
// trì. Tự tải dữ liệu của chính nó ngay khi mount.
import { onMounted, reactive, ref } from "vue";
import employeeService from "../../services/employeeService";
import workShiftService from "../../services/workShiftService";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    employeeId: {
        type: [String, Number],
        required: true,
    },
    canUpdate: {
        type: Boolean,
        default: false,
    },
});

const toast = useToastStore();

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

const WEEK_DAYS = [
    { value: 1, label: "T2" },
    { value: 2, label: "T3" },
    { value: 3, label: "T4" },
    { value: 4, label: "T5" },
    { value: 5, label: "T6" },
    { value: 6, label: "T7" },
    { value: 7, label: "CN" },
];
const WEEK_DAY_LABEL = Object.fromEntries(WEEK_DAYS.map((d) => [d.value, d.label]));

function formatWorkDays(days) {
    if (!days?.length) {
        return "—";
    }
    return [...days]
        .sort((a, b) => a - b)
        .map((d) => WEEK_DAY_LABEL[d] ?? d)
        .join(", ");
}

const shiftAssignments = ref([]);
const shiftAssignmentsLoading = ref(false);
const shiftAssignmentsError = ref("");
const deletingAssignmentId = ref(null);

async function loadShiftAssignments() {
    shiftAssignmentsLoading.value = true;
    shiftAssignmentsError.value = "";
    try {
        const response = await employeeService.shiftAssignments(props.employeeId);
        shiftAssignments.value = response.data;
    } catch (e) {
        shiftAssignmentsError.value =
            e.response?.data?.message ?? "Không thể tải lịch sử ca làm việc.";
    } finally {
        shiftAssignmentsLoading.value = false;
    }
}

async function deleteShiftAssignment(assignment) {
    deletingAssignmentId.value = assignment.id;
    try {
        await employeeService.deleteShiftAssignment(props.employeeId, assignment.id);
        shiftAssignments.value = shiftAssignments.value.filter(
            (a) => a.id !== assignment.id,
        );
        toast.success("Đã xóa ca làm việc đã gán.");
    } catch (e) {
        shiftAssignmentsError.value =
            e.response?.data?.message ?? "Không thể xóa ca làm việc.";
    } finally {
        deletingAssignmentId.value = null;
    }
}

const assignShiftDialog = ref(false);
// null = đang gán ca mới, object = đang sửa bản gán ca này
const editingAssignment = ref(null);
const assignShiftForm = reactive({
    work_shift_id: null,
    effective_from: "",
    work_days: [],
});
const assignShiftErrors = ref({});
const assignShiftGeneralError = ref("");
const assignShiftSubmitting = ref(false);
const workShiftOptions = ref([]);

async function loadWorkShiftOptions() {
    const response = await workShiftService.list({ per_page: 1000 });
    // Chỉ đổ Ca đang hoạt động vào ô chọn — Ca ngừng hoạt động không dùng để
    // gán mới nữa (is_active trả về 1/0 từ DB, không phải boolean thuần).
    workShiftOptions.value = response.data.data
        .filter((s) => Number(s.is_active) === 1)
        .map((s) => ({
            title: `${s.name} (${s.code})`,
            value: s.id,
        }));
}

// Gọi không tham số = mở form Thêm (trống); truyền vào 1 bản gán ca có sẵn =
// mở form Sửa, tự điền lại dữ liệu cũ.
function openAssignShiftDialog(assignment = null) {
    editingAssignment.value = assignment;
    assignShiftForm.work_shift_id = assignment?.work_shift_id ?? null;
    assignShiftForm.effective_from = assignment?.effective_from ?? "";
    assignShiftForm.work_days = assignment ? [...assignment.work_days] : [];
    assignShiftErrors.value = {};
    assignShiftGeneralError.value = "";
    assignShiftDialog.value = true;
    loadWorkShiftOptions();
}

function closeAssignShiftDialog() {
    assignShiftDialog.value = false;
}

async function submitAssignShift() {
    assignShiftErrors.value = {};
    assignShiftGeneralError.value = "";
    assignShiftSubmitting.value = true;
    const payload = {
        work_shift_id: assignShiftForm.work_shift_id,
        effective_from: assignShiftForm.effective_from,
        work_days: assignShiftForm.work_days,
    };
    try {
        const response = editingAssignment.value
            ? await employeeService.updateShiftAssignment(
                  props.employeeId,
                  editingAssignment.value.id,
                  payload,
              )
            : await employeeService.createShiftAssignment(props.employeeId, payload);

        if (editingAssignment.value) {
            const index = shiftAssignments.value.findIndex(
                (a) => a.id === editingAssignment.value.id,
            );
            if (index !== -1) {
                shiftAssignments.value[index] = response.data;
            }
            toast.success("Đã cập nhật ca làm việc.");
        } else {
            shiftAssignments.value = [response.data, ...shiftAssignments.value];
            toast.success("Đã gán ca làm việc.");
        }
        closeAssignShiftDialog();
    } catch (e) {
        const data = e.response?.data;
        if (e.response?.status === 422 && data?.errors) {
            assignShiftErrors.value = {
                work_shift_id: data.errors.work_shift_id?.[0],
                effective_from: data.errors.effective_from?.[0],
                work_days: data.errors.work_days?.[0] ?? data.errors["work_days.0"]?.[0],
            };
        } else {
            assignShiftGeneralError.value = data?.message ?? "Không thể kết nối máy chủ.";
        }
    } finally {
        assignShiftSubmitting.value = false;
    }
}

onMounted(() => {
    loadShiftAssignments();
});
</script>
