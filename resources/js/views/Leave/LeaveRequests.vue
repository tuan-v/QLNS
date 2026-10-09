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

        <!-- Bảng nhiều cột không đọc được trên màn hình hẹp (đã xác nhận qua
             Playwright ở trang Chấm công, mục 26 CODE_MAP) — mobile dùng danh
             sách thẻ xếp dọc thay vì bảng, cùng cách CheckInMobile.vue đã làm
             cho "Lịch sử gần đây" (2026-09-25, theo yêu cầu người dùng). -->
        <div v-if="mobile" class="d-flex flex-column ga-3">
            <div v-if="loadingList" class="d-flex justify-center py-6">
                <v-progress-circular indeterminate size="24" />
            </div>
            <v-sheet
                v-else-if="!myRequests.length"
                class="border rounded-lg pa-5 glass-panel text-center"
                color="transparent"
                style="opacity: 0.6"
            >
                Bạn chưa có đơn xin nghỉ phép nào.
            </v-sheet>
            <v-sheet
                v-for="lr in myRequests"
                v-else
                :key="lr.id"
                class="border rounded-lg pa-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex justify-space-between align-start mb-2">
                    <div>
                        <div class="font-weight-bold">{{ lr.leave_type?.name ?? "—" }}</div>
                        <div class="text-body-2" style="opacity: 0.7">
                            {{ formatDate(lr.from_date) }} - {{ formatDate(lr.to_date) }}
                            ({{ lr.total_days }} ngày)
                        </div>
                    </div>
                    <StatusChip :status="lr.status" :map="LEAVE_STATUS_MAP" />
                </div>
                <div class="text-body-2" style="opacity: 0.75">{{ lr.reason }}</div>
            </v-sheet>
        </div>

        <v-sheet v-else class="border rounded-lg glass-panel" color="transparent">
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
                <v-form
ref="leaveFormRef" validate-on="blur invalid-input lazy" class="v-card-text px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                    @submit.prevent="submit"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại phép <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="form.leaveTypeId"
                            :items="leaveTypeOptions"
                            placeholder="Chọn loại phép"
                            :rules="[notEmpty('Loại phép')]"
                        :error-messages="errors.leave_type_id"
                        />
                        <div v-if="selectedBalanceHint" class="text-caption mt-1 text-primary font-weight-medium">
                            {{ selectedBalanceHint }}
                        </div>
                        <div class="text-caption mt-1" style="opacity: 0.6">
                            Chỉ "Nghỉ phép năm" bị giới hạn số ngày trong năm (hết quỹ sẽ không chọn
                            được nữa) — các loại khác không giới hạn nhưng có thể không được trả lương.
                        </div>
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Từ ngày <span class="text-error">*</span>
                            </div>
                            <InputDate
                                v-model="form.fromDate"
                                :rules="[notEmpty('Từ ngày')]"
                        :error-messages="errors.from_date"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Đến hết ngày <span class="text-error">*</span>
                            </div>
                            <InputDate
                                v-model="form.toDate"
                                :min="form.fromDate || undefined"
                                :rules="[notEmpty('Đến hết ngày')]"
                                :error-messages="errors.to_date || dateRangeError"
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
                                    :rules="[notEmpty('Giờ bắt đầu')]"
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
                                    :rules="[notEmpty('Giờ kết thúc'), (v) => !v || !form.startTime || v > form.startTime || 'Giờ kết thúc phải sau giờ bắt đầu']"
                        :error-messages="errors.end_time"
                                />
                            </v-col>
                        </v-row>
                    </div>
                    <!-- Nhiều ngày: chỉ cho chọn những gì có nghĩa — ngày ĐẦU nghỉ cả
                    ngày hoặc từ buổi chiều, ngày CUỐI nghỉ hết ngày hoặc chỉ buổi
                    sáng (khớp StoreLeaveRequest::withValidator()). -->
                    <v-row v-else dense>
                        <v-col cols="12" sm="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Ngày đầu ({{ formatWeekdayDate(form.fromDate) }})
                            </div>
                            <v-radio-group
                                v-model="form.startSession"
                                density="compact"
                                hide-details="auto"
                                :error-messages="errors.start_session"
                            >
                                <v-radio label="Nghỉ cả ngày" value="full" />
                                <v-radio label="Nghỉ từ buổi chiều" value="pm" />
                            </v-radio-group>
                        </v-col>
                        <v-col cols="12" sm="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Ngày cuối ({{ formatWeekdayDate(form.toDate) }})
                            </div>
                            <v-radio-group
                                v-model="form.endSession"
                                density="compact"
                                hide-details="auto"
                                :error-messages="errors.end_session"
                            >
                                <v-radio label="Nghỉ hết ngày" value="full" />
                                <v-radio label="Chỉ nghỉ buổi sáng" value="am" />
                            </v-radio-group>
                        </v-col>
                    </v-row>
                    <v-alert
                        v-if="leaveSummary"
                        type="info"
                        variant="tonal"
                        density="compact"
                        icon="mdi-calendar-check-outline"
                    >
                        <div>{{ leaveSummary.range }}</div>
                        <div v-if="leaveSummary.returnAt">
                            Đi làm lại: <strong>{{ leaveSummary.returnAt }}</strong>
                        </div>
                        <div v-if="previewTotalDays !== null">
                            Số ngày tính phép: <strong>{{ previewTotalDays }}</strong>
                            <span style="opacity: 0.7">&nbsp;(không tính Thứ 7/Chủ nhật)</span>
                        </div>
                    </v-alert>

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
                            :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
                        :error-messages="errors.reason"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tài liệu đính kèm
                            <span v-if="attachmentRequired" class="text-error">*</span>
                        </div>
                        <InputFile
                            v-model="form.evidenceFile"
                            :limit="UPLOAD_LIMITS.leaveEvidence"
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
                </v-form>
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
import { computed, onMounted, ref, watch } from "vue";
import { useDisplay } from "vuetify";
import leaveRequestService from "../../services/leaveRequestService";
import leaveTypeService from "../../services/leaveTypeService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import InputFile, { UPLOAD_LIMITS } from "../../components/common/InputFile.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { maxLength, notEmpty, useClearErrorsOnEdit } from "../../composables/validationRules";
import { useRouteAction } from "../../composables/useRouteAction";

const toast = useToastStore();
const { mobile } = useDisplay();

const LEAVE_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "info" },
    manager_approved: { label: "Đã duyệt cấp Quản lý", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "error" },
};

const WEEKDAY_LABELS = ["Chủ nhật", "Thứ Hai", "Thứ Ba", "Thứ Tư", "Thứ Năm", "Thứ Sáu", "Thứ Bảy"];

// "YYYY-MM-DD" -> "Thứ Năm 08/10"
function formatWeekdayDate(value) {
    const date = typeof value === "string" ? parseLocalDate(value) : value;
    if (!date) {
        return "";
    }
    const pad = (n) => String(n).padStart(2, "0");
    return `${WEEKDAY_LABELS[date.getDay()]} ${pad(date.getDate())}/${pad(date.getMonth() + 1)}`;
}

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

/* ---------------------------- Quỹ phép còn lại ---------------------------- */

// Chỉ hiện thẻ cho loại phép nào THẬT SỰ có khái niệm quỹ theo năm
// (leave_type.annual_entitlement_days > 0) — hiện tại chỉ "Nghỉ phép năm",
// các loại khác ("Nghỉ khác theo chế độ/luật", "Nghỉ không lương") đặt
// entitlement = 0 vì tính theo chế độ riêng, không trừ vào quỹ phép năm
// (mục 19).
//
// 2026-09-24 (theo yêu cầu người dùng, sau khi phát hiện thẻ "biến mất"
// hoàn toàn với nhân viên mới): TRƯỚC lọc theo `allocated_days > 0` — đúng
// với thiết kế cũ (cấp đủ 12 ngay từ đầu nên allocated_days luôn > 0 với
// "Nghỉ phép năm"), nhưng từ khi có tích lũy theo 30 ngày (mục 29,
// LeaveAccrualService), nhân viên MỚI có `allocated_days = 0` trong ~1
// tháng đầu — lọc theo allocated_days vô tình ẩn LUÔN thẻ, trông như tính
// năng bị hỏng thay vì "đang tích lũy". Đổi sang lọc theo
// `leave_type.annual_entitlement_days` (loại phép có ÁP DỤNG quỹ hay không,
// không phải đã tích lũy được bao nhiêu) để vẫn hiện thẻ ở trạng thái
// "đang tích lũy" cho nhân viên mới.
const balances = ref([]);

// Hiện "available_days" (đã trừ cả các đơn đang chờ duyệt) làm số chính —
// không dùng "remaining_days" thô, vì remaining_days chỉ trừ đơn ĐÃ duyệt,
// dễ khiến nhân viên tưởng còn nhiều hơn thực tế và gửi chồng đơn (mục 19).
const balanceStats = computed(() =>
    balances.value
        .filter((b) => b.leave_type.annual_entitlement_days > 0)
        .map((b) => {
            // Chưa tích lũy được ngày nào (nhân viên mới, <30 ngày làm) —
            // hiện riêng, màu "info" (trung tính) thay vì "error" (đỏ, dễ
            // hiểu nhầm là đang thiếu/vượt quỹ).
            if (b.allocated_days <= 0) {
                return {
                    label: `${b.leave_type.name} — đang tích lũy (đủ 30 ngày làm được +1 ngày)`,
                    value: "0 ngày",
                    color: "info",
                    icon: "mdi-calendar-clock-outline",
                };
            }

            return {
                label:
                    b.pending_days > 0
                        ? `${b.leave_type.name} khả dụng (${b.pending_days} ngày đang chờ duyệt)`
                        : `${b.leave_type.name} còn lại`,
                value: `${b.available_days}/${b.allocated_days} ngày`,
                color: b.available_days > 0 ? "success" : "error",
                icon: "mdi-calendar-star-outline",
            };
        }),
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

async function loadMine(opts) {
    const silent = opts?.silent === true;
    if (!silent) {
        loadingList.value = true;
    }
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
// Danh sách THÔ nạp từ API, chưa gắn trạng thái "hết quỹ" (cần đối chiếu
// với `balances` — xem leaveTypeOptions computed bên dưới).
const rawLeaveTypes = ref([]);

// Ngày làm việc kế tiếp (bỏ T7/CN) dạng "YYYY-MM-DD" theo giờ máy — mặc định của đơn mới.
function nextWorkingDayIso() {
    const d = new Date();
    do {
        d.setDate(d.getDate() + 1);
    } while (d.getDay() === 0 || d.getDay() === 6);
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

// Nhớ loại phép đã dùng lần trước để chọn sẵn.
const lastLeaveTypeId = useRememberedRef("leave-requests.last-type", null);

const defaultForm = () => ({
    leaveTypeId: null,
    fromDate: nextWorkingDayIso(),
    toDate: nextWorkingDayIso(),
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

// Đổi "Từ ngày" sang sau "Đến ngày" (hoặc "Đến ngày" còn trống) thì "Đến ngày"
// tự nhảy theo — người dùng không phải sửa 2 lần, cũng không thấy lỗi vô lý.
watch(
    () => form.value.fromDate,
    (fromDate) => {
        if (fromDate && (!form.value.toDate || form.value.toDate < fromDate)) {
            form.value.toDate = fromDate;
        }
    },
);

// Tính lại theo CẢ 2 ô mỗi lần đổi (không dùng :rules vì rules chỉ chạy lại khi
// chính ô "Đến ngày" đổi — sửa "Từ ngày" xong lỗi cũ vẫn treo).
const dateRangeError = computed(() =>
    form.value.fromDate && form.value.toDate && form.value.toDate < form.value.fromDate
        ? "Đến hết ngày phải cùng hoặc sau Từ ngày"
        : "",
);

const isSingleDay = computed(
    () => !!form.value.fromDate && !!form.value.toDate && form.value.fromDate === form.value.toDate,
);

const isHourly = computed(() => isSingleDay.value && form.value.session === "hourly");

// Ngày làm việc kế tiếp sau `date` (bỏ T7/CN — cùng quy ước tính ngày phép).
function nextWorkingDay(date) {
    const d = new Date(date);
    do {
        d.setDate(d.getDate() + 1);
    } while (d.getDay() === 0 || d.getDay() === 6);
    return d;
}

// Câu tóm tắt "nghỉ từ … đến hết … — đi làm lại …" để người dùng khỏi phải
// đoán "Đến ngày" là ngày đi làm lại hay ngày nghỉ cuối cùng.
const leaveSummary = computed(() => {
    const from = parseLocalDate(form.value.fromDate);
    const to = parseLocalDate(form.value.toDate);
    if (!from || !to || to < from) {
        return null;
    }

    if (isSingleDay.value) {
        const day = formatWeekdayDate(from);
        if (form.value.session === "hourly") {
            return form.value.startTime && form.value.endTime
                ? { range: `Nghỉ từ ${form.value.startTime} đến ${form.value.endTime} ${day}.`, returnAt: `${form.value.endTime} cùng ngày` }
                : null;
        }
        if (form.value.session === "am") {
            return { range: `Nghỉ buổi sáng ${day}.`, returnAt: `buổi chiều ${day}` };
        }
        if (form.value.session === "pm") {
            return { range: `Nghỉ buổi chiều ${day}.`, returnAt: `buổi sáng ${formatWeekdayDate(nextWorkingDay(from))}` };
        }
        return { range: `Nghỉ cả ngày ${day}.`, returnAt: `buổi sáng ${formatWeekdayDate(nextWorkingDay(from))}` };
    }

    const start = `${form.value.startSession === "pm" ? "chiều" : "sáng"} ${formatWeekdayDate(from)}`;
    const end = `${form.value.endSession === "am" ? "sáng" : "chiều"} ${formatWeekdayDate(to)}`;
    const returnAt = form.value.endSession === "am"
        ? `buổi chiều ${formatWeekdayDate(to)}`
        : `buổi sáng ${formatWeekdayDate(nextWorkingDay(to))}`;

    return { range: `Nghỉ từ ${start} đến hết ${end}.`, returnAt };
});

const attachmentRequired = computed(() => {
    const option = leaveTypeOptions.value.find((o) => o.value === form.value.leaveTypeId);
    // "Nghỉ khác theo chế độ/luật" (gộp ốm/thai sản/chế độ cha-mẹ..., 2026-09-24)
    // — khớp StoreLeaveRequest::withValidator() ở backend.
    return option?.code === "other";
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

// Loại phép CÓ giới hạn quỹ (annual_entitlement_days > 0) mà số ngày khả
// dụng đã về 0 (chưa tích lũy đủ, hoặc đã dùng/đăng ký hết) -> KHÔNG cho
// chọn (2026-09-24, theo yêu cầu người dùng: "không có ngày nghỉ phép năm
// thì không cho tạo đơn với loại nghỉ phép năm") — chặn NGAY từ lúc chọn
// loại phép, không để điền hết form rồi mới bị 422 từ backend (backend vẫn
// giữ nguyên phần chặn này — LeaveRequestService::create() — làm lưới an
// toàn cuối cùng, phòng trường hợp dữ liệu balances ở đây bị lỗi thời).
const leaveTypeOptions = computed(() =>
    rawLeaveTypes.value.map((lt) => {
        const balance = balances.value.find((b) => b.leave_type.id === lt.id);
        const outOfQuota = lt.annual_entitlement_days > 0 && balance && balance.available_days <= 0;

        return {
            title: outOfQuota ? `${describeLeaveType(lt)} — hết quỹ, không thể chọn` : describeLeaveType(lt),
            value: lt.id,
            code: lt.code,
            props: { disabled: outOfQuota },
        };
    }),
);

async function loadLeaveTypeOptions() {
    try {
        const response = await leaveTypeService.list();
        rawLeaveTypes.value = response.data;
    } catch {
        rawLeaveTypes.value = [];
    }
    preselectLeaveType();
}

// Chọn sẵn loại phép: loại dùng lần trước nếu còn chọn được, không thì để trống.
function preselectLeaveType() {
    if (form.value.leaveTypeId || !lastLeaveTypeId.value) return;
    const option = leaveTypeOptions.value.find((o) => o.value === lastLeaveTypeId.value);
    if (option && !option.props?.disabled) {
        form.value.leaveTypeId = option.value;
    }
}

// Số ngày còn lại của loại phép đang chọn — hiện ngay dưới ô, không phải nhìn lên thẻ thống kê.
const selectedBalanceHint = computed(() => {
    const id = form.value.leaveTypeId;
    if (!id) return "";
    const lt = rawLeaveTypes.value.find((t) => t.id === id);
    const balance = balances.value.find((b) => b.leave_type.id === id);
    if (!lt || !(lt.annual_entitlement_days > 0) || !balance) return "";
    const pending = balance.pending_days > 0 ? ` (đang chờ duyệt ${Number(balance.pending_days)} ngày)` : "";
    return `Còn ${Number(balance.available_days)} ngày khả dụng${pending}.`;
});

watch(balances, preselectLeaveType);

function openDialog() {
    form.value = defaultForm();
    errors.value = {};
    generalError.value = "";
    dialog.value = true;
    loadLeaveTypeOptions();
    // Nạp lại số dư MỚI NHẤT mỗi lần mở form — tránh dùng số dư cũ từ lúc
    // vào trang (vd vừa nộp 1 đơn khác lúc nãy làm available_days đổi) khi
    // xét loại phép nào bị khóa (xem leaveTypeOptions computed).
    loadBalances();
}

function closeDialog() {
    dialog.value = false;
}

function pickedFile(value) {
    return Array.isArray(value) ? value[0] : value;
}

const leaveFormRef = ref(null);
useClearErrorsOnEdit(() => form.value, () => () => errors.value, { leaveTypeId: "leave_type_id", fromDate: "from_date", toDate: "to_date", startTime: "start_time", endTime: "end_time", startSession: "start_session", endSession: "end_session", evidenceFile: "evidence_file" });

async function submit() {
    const { valid } = await leaveFormRef.value.validate();
    if (!valid || dateRangeError.value) {
        return;
    }
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
        lastLeaveTypeId.value = form.value.leaveTypeId;
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

useRealtimeRefresh((opts) => Promise.all([loadBalances(), loadMine(opts)]), {
    mine: ["leave_requests", "leave_balances"],
});

// Mở thẳng thao tác khi vào trang bằng ?action=... (lệnh Ctrl+K).
useRouteAction({ create: openDialog });

onMounted(() => {
    loadMine();
    loadBalances();
});
</script>
