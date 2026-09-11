<template>
    <div>
        <PageHeader
            title="Nghỉ phép"
            subtitle="Tạo đơn xin nghỉ phép và theo dõi trạng thái duyệt."
        >
            <template #actions>
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-calendar-plus"
                    @click="openDialog"
                >
                    Tạo đơn xin nghỉ phép
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

        <!-- Quỹ phép còn lại -->
        <StatCards v-if="balanceStats.length" :stats="balanceStats" />

        <!-- Đơn của tôi -->
        <div class="text-subtitle-1 font-weight-bold mb-3 mt-4">Đơn của tôi</div>
        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Loại phép</th>
                        <th>Từ ngày</th>
                        <th>Đến ngày</th>
                        <th>Số ngày</th>
                        <th>Lý do</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loadingList">
                        <td colspan="6" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!myRequests.length">
                        <td colspan="6" class="text-center py-6" style="opacity: 0.6">
                            Bạn chưa có đơn xin nghỉ phép nào.
                        </td>
                    </tr>
                    <tr v-for="lr in myRequests" v-else :key="lr.id">
                        <td>{{ lr.leave_type?.name ?? "—" }}</td>
                        <td>{{ formatDate(lr.from_date) }}</td>
                        <td>{{ formatDate(lr.to_date) }}</td>
                        <td>{{ lr.total_days }}</td>
                        <td class="text-truncate" style="max-width: 240px">{{ lr.reason }}</td>
                        <td>
                            <StatusChip :status="lr.status" :map="LEAVE_STATUS_MAP" />
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <!-- Tạo đơn xin nghỉ phép -->
        <v-dialog v-model="dialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Tạo đơn xin nghỉ phép
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại phép <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="form.leaveTypeId"
                            :items="leaveTypeOptions"
                            placeholder="Chọn loại phép"
                            :error-messages="errors.leave_type_id"
                        />
                        <div class="text-caption mt-1" style="opacity: 0.6">
                            Chỉ "Nghỉ phép năm" bị giới hạn số ngày trong năm — các loại khác (Ốm,
                            Thai sản, Không lương...) không giới hạn nhưng có thể không được trả lương.
                        </div>
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Từ ngày <span class="text-error">*</span>
                            </div>
                            <InputDate
                                v-model="form.fromDate"
                                :error-messages="errors.from_date"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Đến ngày <span class="text-error">*</span>
                            </div>
                            <InputDate
                                v-model="form.toDate"
                                :min="form.fromDate || undefined"
                                :error-messages="errors.to_date"
                            />
                        </v-col>
                    </v-row>

                    <!-- 1 ngày duy nhất: gộp lại thành "Thời gian" giống mockup, có thêm
                    "Theo giờ". Nhiều ngày thì giữ 2 ô Buổi bắt đầu/kết thúc riêng như cũ
                    (nghỉ theo giờ không có ý nghĩa khi trải dài nhiều ngày). -->
                    <div v-if="isSingleDay">
                        <div class="text-body-2 font-weight-medium mb-1">Thời gian</div>
                        <v-radio-group v-model="form.session" density="comfortable" hide-details>
                            <v-radio label="Cả ngày" value="full" />
                            <v-radio label="Buổi sáng" value="am" />
                            <v-radio label="Buổi chiều" value="pm" />
                            <v-radio label="Theo giờ" value="hourly" />
                        </v-radio-group>
                        <v-row v-if="form.session === 'hourly'" dense class="mt-1">
                            <v-col cols="6">
                                <div class="text-body-2 font-weight-medium mb-1">
                                    Giờ bắt đầu <span class="text-error">*</span>
                                </div>
                                <v-text-field
                                    v-model="form.startTime"
                                    type="time"
                                    variant="outlined"
                                    density="comfortable"
                                    rounded="lg"
                                    :error-messages="errors.start_time"
                                />
                            </v-col>
                            <v-col cols="6">
                                <div class="text-body-2 font-weight-medium mb-1">
                                    Giờ kết thúc <span class="text-error">*</span>
                                </div>
                                <v-text-field
                                    v-model="form.endTime"
                                    type="time"
                                    variant="outlined"
                                    density="comfortable"
                                    rounded="lg"
                                    :error-messages="errors.end_time"
                                />
                            </v-col>
                        </v-row>
                    </div>
                    <v-row v-else dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">Buổi bắt đầu</div>
                            <v-select
                                v-model="form.startSession"
                                :items="SESSION_OPTIONS"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                                :error-messages="errors.start_session"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">Buổi kết thúc</div>
                            <v-select
                                v-model="form.endSession"
                                :items="SESSION_OPTIONS"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                                :error-messages="errors.end_session"
                            />
                        </v-col>
                    </v-row>
                    <div class="text-caption" style="opacity: 0.65">
                        Thứ 7/Chủ nhật là ngày nghỉ cố định, không tính vào số ngày phép.
                        <span v-if="previewTotalDays !== null">
                            Số ngày: <strong>{{ previewTotalDays }}</strong>
                        </span>
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="form.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="errors.reason"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tài liệu đính kèm
                            <span v-if="attachmentRequired" class="text-error">*</span>
                        </div>
                        <v-file-input
                            v-model="form.evidenceFile"
                            placeholder="Chọn ảnh (JPG, PNG) hoặc PDF, tối đa 5MB"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            accept=".pdf,.jpg,.jpeg,.png"
                            :error-messages="errors.evidence_file"
                        />
                        <div v-if="attachmentRequired" class="text-caption" style="opacity: 0.6">
                            Loại phép này bắt buộc đính kèm tài liệu (giấy khám bệnh, giấy chứng
                            sinh...).
                        </div>
                    </div>

                    <v-alert
                        v-if="generalError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ generalError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" :disabled="submitting" @click="closeDialog">
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="submitting"
                        @click="submit"
                    >
                        Gửi đơn
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Trang tự phục vụ — nhân viên tạo đơn xin nghỉ phép CHO CHÍNH MÌNH (backend
// luôn tự resolve employee_id theo token đăng nhập, không nhận từ client,
// xem LeaveRequestController::store()). Cùng khuôn với CheckIn.vue: dùng
// v-dialog + validate tay thay vì FormDialog/FormSection (những component đó
// dành cho CRUD admin như EmployeeForm.vue, không phù hợp cho luồng tự phục
// vụ đơn giản này).
import { computed, onMounted, ref } from "vue";
import leaveRequestService from "../../services/leaveRequestService";
import leaveTypeService from "../../services/leaveTypeService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const LEAVE_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "info" },
    manager_approved: { label: "Đã duyệt cấp Quản lý", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "error" },
};

const SESSION_OPTIONS = [
    { title: "Cả ngày", value: "full" },
    { title: "Sáng", value: "am" },
    { title: "Chiều", value: "pm" },
];

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

/* ---------------------------- Quỹ phép còn lại ---------------------------- */

// Chỉ hiện thẻ cho loại phép nào THẬT SỰ có hạn mức (allocated_days > 0) —
// hiện tại chỉ "Nghỉ phép năm" (12 ngày/năm), các loại khác (ốm, thai sản...)
// đang để 0 vì tính theo chế độ riêng, không trừ vào quỹ phép năm (mục 19).
const balances = ref([]);

// Hiện "available_days" (đã trừ cả các đơn đang chờ duyệt) làm số chính —
// không dùng "remaining_days" thô, vì remaining_days chỉ trừ đơn ĐÃ duyệt,
// dễ khiến nhân viên tưởng còn nhiều hơn thực tế và gửi chồng đơn (mục 19).
const balanceStats = computed(() =>
    balances.value
        .filter((b) => b.allocated_days > 0)
        .map((b) => ({
            label:
                b.pending_days > 0
                    ? `${b.leave_type.name} khả dụng (${b.pending_days} ngày đang chờ duyệt)`
                    : `${b.leave_type.name} còn lại`,
            value: `${b.available_days}/${b.allocated_days} ngày`,
            color: b.available_days > 0 ? "success" : "error",
            icon: "mdi-calendar-star-outline",
        })),
);

async function loadBalances() {
    try {
        const response = await leaveRequestService.balancesMine();
        balances.value = response.data;
    } catch {
        balances.value = [];
    }
}

/* ------------------------------- Danh sách ------------------------------- */

const myRequests = ref([]);
const loadingList = ref(true);
const loadError = ref("");

async function loadMine() {
    loadingList.value = true;
    try {
        const response = await leaveRequestService.mine();
        myRequests.value = response.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải danh sách đơn nghỉ phép.";
    } finally {
        loadingList.value = false;
    }
}

/* --------------------------------- Form --------------------------------- */

const dialog = ref(false);
const submitting = ref(false);
const errors = ref({});
const generalError = ref("");
const leaveTypeOptions = ref([]);

const defaultForm = () => ({
    leaveTypeId: null,
    fromDate: "",
    toDate: "",
    // "session" dùng khi 1 ngày (radio Cả ngày/Sáng/Chiều/Theo giờ),
    // "startSession"/"endSession" dùng khi nhiều ngày (2 ô riêng như cũ).
    session: "full",
    startSession: "full",
    endSession: "full",
    startTime: "",
    endTime: "",
    reason: "",
    evidenceFile: null,
});
const form = ref(defaultForm());

const isSingleDay = computed(
    () => !!form.value.fromDate && !!form.value.toDate && form.value.fromDate === form.value.toDate,
);

const isHourly = computed(() => isSingleDay.value && form.value.session === "hourly");

const attachmentRequired = computed(() => {
    const option = leaveTypeOptions.value.find((o) => o.value === form.value.leaveTypeId);
    return ["sick", "maternity", "paternity"].includes(option?.code);
});

// Preview client-side, mô phỏng lại đúng logic backend
// (LeaveRequestService::calculateTotalDays()/calculateHourlyDays()): bỏ qua
// Thứ 7/CN, nửa ngày ở 2 đầu, quy đổi 8 giờ = 1 ngày công khi "Theo giờ".
function parseLocalDate(value) {
    if (!value) {
        return null;
    }
    const [y, m, d] = value.split("-").map(Number);
    return new Date(y, m - 1, d);
}

const previewTotalDays = computed(() => {
    if (!form.value.fromDate || !form.value.toDate) {
        return null;
    }

    if (isHourly.value) {
        if (!form.value.startTime || !form.value.endTime) {
            return null;
        }
        const [sh, sm] = form.value.startTime.split(":").map(Number);
        const [eh, em] = form.value.endTime.split(":").map(Number);
        const minutes = eh * 60 + em - (sh * 60 + sm);
        return minutes > 0 ? Math.round((minutes / 60 / 8) * 100) / 100 : null;
    }

    const from = parseLocalDate(form.value.fromDate);
    const to = parseLocalDate(form.value.toDate);
    if (!from || !to || to < from) {
        return null;
    }

    const startSession = isSingleDay.value ? form.value.session : form.value.startSession;
    const endSession = isSingleDay.value ? form.value.session : form.value.endSession;
    const sameDay = from.getTime() === to.getTime();

    let total = 0;
    const cursor = new Date(from);
    while (cursor <= to) {
        const dow = cursor.getDay();
        if (dow !== 0 && dow !== 6) {
            if (sameDay) {
                total += startSession === "full" ? 1 : 0.5;
            } else if (cursor.getTime() === from.getTime() && startSession !== "full") {
                total += 0.5;
            } else if (cursor.getTime() === to.getTime() && endSession !== "full") {
                total += 0.5;
            } else {
                total += 1;
            }
        }
        cursor.setDate(cursor.getDate() + 1);
    }

    return Math.round(total * 100) / 100;
});

// Ghi rõ ngay trong tên từng lựa chọn: có lương hay không + có giới hạn số
// ngày/năm hay không — người dùng hỏi "làm sao phân biệt được để chọn",
// tránh phải nhớ hay tra cứu riêng lúc đang điền form.
function describeLeaveType(lt) {
    const payLabel = lt.is_paid ? "Có lương" : "Không lương";
    const quotaLabel = lt.annual_entitlement_days > 0
        ? `giới hạn ${Number(lt.annual_entitlement_days)} ngày/năm`
        : "không giới hạn ngày";

    return `${lt.name} — ${payLabel}, ${quotaLabel}`;
}

async function loadLeaveTypeOptions() {
    try {
        const response = await leaveTypeService.list();
        leaveTypeOptions.value = response.data.map((lt) => ({
            title: describeLeaveType(lt),
            value: lt.id,
            code: lt.code,
        }));
    } catch {
        leaveTypeOptions.value = [];
    }
}

function openDialog() {
    form.value = defaultForm();
    errors.value = {};
    generalError.value = "";
    dialog.value = true;
    loadLeaveTypeOptions();
}

function closeDialog() {
    dialog.value = false;
}

function pickedFile(value) {
    return Array.isArray(value) ? value[0] : value;
}

async function submit() {
    errors.value = {};
    generalError.value = "";

    if (!form.value.leaveTypeId || !form.value.fromDate || !form.value.toDate || !form.value.reason) {
        generalError.value = "Vui lòng nhập đủ loại phép, từ ngày, đến ngày và lý do.";
        return;
    }
    if (isHourly.value && (!form.value.startTime || !form.value.endTime)) {
        generalError.value = "Vui lòng nhập giờ bắt đầu và giờ kết thúc.";
        return;
    }
    const file = pickedFile(form.value.evidenceFile);
    if (attachmentRequired.value && !file) {
        generalError.value = "Loại phép này bắt buộc đính kèm tài liệu.";
        return;
    }

    const startSession = isSingleDay.value ? form.value.session : form.value.startSession;
    const endSession = isSingleDay.value ? form.value.session : form.value.endSession;

    const formData = new FormData();
    formData.append("leave_type_id", form.value.leaveTypeId);
    formData.append("from_date", form.value.fromDate);
    formData.append("to_date", form.value.toDate);
    formData.append("start_session", startSession);
    formData.append("end_session", endSession);
    if (isHourly.value) {
        formData.append("start_time", form.value.startTime);
        formData.append("end_time", form.value.endTime);
    }
    formData.append("reason", form.value.reason);
    if (file) {
        formData.append("evidence_file", file);
    }

    submitting.value = true;
    try {
        await leaveRequestService.create(formData);
        toast.success("Đã gửi đơn xin nghỉ phép, chờ duyệt.");
        closeDialog();
        await Promise.all([loadMine(), loadBalances()]);
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            errors.value = {
                leave_type_id: data.errors.leave_type_id?.[0],
                from_date: data.errors.from_date?.[0],
                to_date: data.errors.to_date?.[0],
                start_session: data.errors.start_session?.[0],
                end_session: data.errors.end_session?.[0],
                start_time: data.errors.start_time?.[0],
                end_time: data.errors.end_time?.[0],
                reason: data.errors.reason?.[0],
                evidence_file: data.errors.evidence_file?.[0],
            };
        } else {
            generalError.value = data?.message ?? "Không thể gửi đơn, vui lòng thử lại.";
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    loadMine();
    loadBalances();
});
</script>
