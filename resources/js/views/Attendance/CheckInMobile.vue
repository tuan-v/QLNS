<template>
    <div>
        <PageHeader
            title="Chấm công"
            subtitle="Chấm công vào/ra theo Wifi, GPS hoặc mã QR."
        >
            <template #actions>
                <v-btn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-edit"
                    block
                    @click="openSupplementDialog"
                >
                    Xin bổ sung chấm công
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

        <!-- Chọn phương thức -->
        <v-sheet class="border rounded-lg mb-4 glass-panel" color="transparent">
            <v-tabs v-model="method" grow>
                <v-tab value="wifi">Wifi</v-tab>
                <v-tab value="gps">GPS</v-tab>
                <v-tab value="qr">Mã QR</v-tab>
            </v-tabs>
        </v-sheet>

        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <v-window v-model="method">
                <v-window-item value="wifi">
                    <div class="text-body-2" style="opacity: 0.75">
                        Chấm công qua mạng Wifi công ty — hệ thống tự nhận diện
                        theo địa chỉ mạng, không cần nhập gì thêm. Bấm nút ở
                        ca tương ứng bên dưới khi đang kết nối đúng Wifi tại
                        nơi làm việc.
                    </div>
                </v-window-item>

                <v-window-item value="gps">
                    <div class="text-body-2" style="opacity: 0.75">
                        Chấm công theo vị trí hiện tại — trình duyệt sẽ hỏi
                        quyền truy cập vị trí khi bạn bấm nút ở ca tương ứng
                        bên dưới.
                    </div>
                </v-window-item>

                <v-window-item value="qr">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Mã QR tại điểm chấm công
                    </div>
                    <v-text-field
                        v-model="qrReference"
                        placeholder="Quét hoặc dán nội dung mã QR"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                    />
                </v-window-item>
            </v-window>

            <v-alert
                v-if="submitError"
                type="error"
                variant="tonal"
                density="compact"
                class="mt-3"
            >
                {{ submitError }}
            </v-alert>
        </v-sheet>

        <!-- Trạng thái từng ca hôm nay — luôn 1 cột trên mobile (khác desktop
        chia 2 cột md="6"), mỗi ca 1 thẻ riêng để dễ bấm bằng ngón tay. -->
        <div v-if="loadingToday" class="d-flex justify-center py-6">
            <v-progress-circular indeterminate size="24" />
        </div>
        <v-sheet
            v-else-if="!todayShifts.length"
            class="border rounded-lg pa-5 mb-4 glass-panel text-center"
            color="transparent"
            style="opacity: 0.7"
        >
            Hôm nay bạn không có ca làm việc nào.
        </v-sheet>
        <div v-else class="d-flex flex-column ga-3 mb-4">
            <v-sheet
                v-for="entry in todayShifts"
                :key="entry.work_shift.id"
                class="border rounded-lg pa-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex justify-space-between align-start mb-3">
                    <div>
                        <div class="text-subtitle-1 font-weight-bold">
                            {{ entry.work_shift.name }}
                        </div>
                        <div class="text-body-2" style="opacity: 0.7">
                            {{ entry.work_shift.start_time }} - {{ entry.work_shift.end_time }}
                        </div>
                    </div>
                    <StatusChip
                        v-if="entry.attendance"
                        :status="entry.attendance.status"
                        :map="ATTENDANCE_STATUS_MAP"
                    />
                </div>

                <div class="d-flex justify-space-between text-body-2 py-1">
                    <span style="opacity: 0.75">Check-in</span>
                    <span class="d-flex align-center font-weight-medium">
                        {{ formatTime(entry.attendance?.first_check_in_at) }}
                        <v-icon
                            v-if="entry.attendance?.first_check_in_at"
                            icon="mdi-check-circle"
                            color="success"
                            size="16"
                            class="ml-1"
                        />
                    </span>
                </div>
                <div class="d-flex justify-space-between text-body-2 py-1 mb-2">
                    <span style="opacity: 0.75">Check-out</span>
                    <span class="d-flex align-center font-weight-medium">
                        {{ formatTime(entry.attendance?.last_check_out_at) }}
                        <v-icon
                            v-if="entry.attendance?.last_check_out_at"
                            icon="mdi-check-circle"
                            color="success"
                            size="16"
                            class="ml-1"
                        />
                    </span>
                </div>

                <v-btn
                    v-if="!entry.attendance?.first_check_in_at"
                    color="primary"
                    variant="flat"
                    size="large"
                    block
                    :loading="submittingShiftId === entry.work_shift.id"
                    :disabled="submitting"
                    @click="submit(entry.work_shift.id, false)"
                >
                    Chấm công vào
                </v-btn>
                <v-btn
                    v-else-if="!entry.attendance?.last_check_out_at"
                    color="warning"
                    variant="flat"
                    size="large"
                    block
                    :loading="submittingShiftId === entry.work_shift.id"
                    :disabled="submitting"
                    @click="submit(entry.work_shift.id, true)"
                >
                    Chấm công ra
                </v-btn>
            </v-sheet>
        </div>

        <!-- Lịch sử gần đây — danh sách thẻ xếp dọc thay vì bảng nhiều cột:
        1 bảng 9 cột không thể đọc được trên màn hình hẹp (đã xác nhận qua
        Playwright, mục 26 CODE_MAP), mỗi lượt chấm công gom lại thành 1 thẻ. -->
        <div class="d-flex justify-space-between align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">Lịch sử gần đây</div>
            <v-btn
                variant="text"
                color="primary"
                size="small"
                append-icon="mdi-arrow-right"
                :to="{ name: 'attendance-history' }"
            >
                Xem đầy đủ
            </v-btn>
        </div>
        <div v-if="loadingHistory" class="d-flex justify-center py-6">
            <v-progress-circular indeterminate size="24" />
        </div>
        <v-sheet
            v-else-if="!history.length"
            class="border rounded-lg pa-5 glass-panel text-center"
            color="transparent"
            style="opacity: 0.6"
        >
            Chưa có lịch sử chấm công.
        </v-sheet>
        <div v-else class="d-flex flex-column ga-3">
            <v-sheet
                v-for="a in history"
                :key="a.id"
                class="border rounded-lg pa-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex justify-space-between align-start mb-2">
                    <div>
                        <div class="font-weight-bold">{{ formatDate(a.attendance_date) }}</div>
                        <div class="text-body-2" style="opacity: 0.7">
                            {{ a.work_shift?.name ?? "—" }}
                        </div>
                    </div>
                    <StatusChip :status="a.status" :map="ATTENDANCE_STATUS_MAP" />
                </div>

                <div class="d-flex justify-space-between text-body-2 py-1">
                    <span style="opacity: 0.75">Giờ vào - Giờ ra</span>
                    <span class="font-weight-medium">
                        {{ formatTime(a.first_check_in_at) }} - {{ formatTime(a.last_check_out_at) }}
                    </span>
                </div>

                <div
                    v-if="a.late_minutes || a.early_leave_minutes || a.overtime_minutes"
                    class="d-flex flex-wrap ga-2 mt-2"
                >
                    <v-chip v-if="a.late_minutes" size="small" :color="a.late_excused ? 'success' : 'warning'" variant="tonal">
                        Trễ {{ formatMinutesAsHours(a.late_minutes) }}{{ a.late_excused ? " (đã miễn trừ)" : "" }}
                    </v-chip>
                    <v-chip v-if="a.early_leave_minutes" size="small" color="warning" variant="tonal">
                        Về sớm {{ formatMinutesAsHours(a.early_leave_minutes) }}
                    </v-chip>
                    <v-chip v-if="a.overtime_minutes" size="small" color="info" variant="tonal">
                        OT {{ formatMinutesAsHours(a.overtime_minutes) }}
                    </v-chip>
                </div>

                <div class="d-flex flex-wrap ga-2 mt-3">
                    <v-btn
                        size="small"
                        variant="tonal"
                        rounded="lg"
                        prepend-icon="mdi-file-edit-outline"
                        @click="openAdjustDialog(a)"
                    >
                        Xin điều chỉnh
                    </v-btn>
                    <v-btn
                        v-if="a.late_minutes > 0 && !a.late_excused"
                        size="small"
                        variant="tonal"
                        color="secondary"
                        rounded="lg"
                        prepend-icon="mdi-shield-check-outline"
                        @click="openExcuseDialog(a)"
                    >
                        Miễn trừ đi muộn
                    </v-btn>
                </div>
            </v-sheet>
        </div>

        <!-- Xin điều chỉnh (correction — sửa 1 bản ghi ĐÃ CÓ) -->
        <v-dialog v-model="adjustDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold">Xin điều chỉnh công</v-toolbar-title>
                    <v-btn icon="mdi-close" :disabled="adjustSubmitting" @click="closeAdjustDialog" />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công: <strong>{{ formatDate(adjustTarget?.attendance_date) }}</strong>.
                        Chỉ cần đề xuất giờ nào bị sai, để trống giờ còn lại
                        nếu không cần sửa.
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ vào đúng
                            </div>
                            <v-text-field
                                v-model="adjustForm.checkInTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ ra đúng
                            </div>
                            <v-text-field
                                v-model="adjustForm.checkOutTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                    </v-row>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="adjustForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="adjustErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="adjustGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ adjustGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="adjustSubmitting"
                        @click="submitAdjustRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xin bổ sung chấm công (supplement — quên chấm công hoàn toàn,
        chưa có bản ghi nào cho ca+ngày đó) -->
        <v-dialog v-model="supplementDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold">Xin bổ sung chấm công</v-toolbar-title>
                    <v-btn icon="mdi-close" :disabled="supplementSubmitting" @click="closeSupplementDialog" />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Dùng khi bạn quên chấm công cả ngày (không có bản ghi
                        nào để xin điều chỉnh). Phải nhập đủ cả giờ vào lẫn
                        giờ ra.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="supplementForm.attendanceDate"
                            :max="todayIso()"
                            :error-messages="supplementErrors.attendance_date"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ca làm việc <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="supplementForm.workShiftId"
                            :items="shiftOptions"
                            placeholder="Chọn ca làm việc"
                            :error-messages="supplementErrors.work_shift_id"
                        />
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ vào <span class="text-error">*</span>
                            </div>
                            <v-text-field
                                v-model="supplementForm.checkInTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ ra <span class="text-error">*</span>
                            </div>
                            <v-text-field
                                v-model="supplementForm.checkOutTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                    </v-row>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="supplementForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="supplementErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="supplementGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ supplementGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="supplementSubmitting"
                        @click="submitSupplementRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xin miễn trừ đi muộn (excuse — Ngày 42, KHÔNG sửa giờ, chỉ xin
        không tính vào thống kê đi muộn) -->
        <v-dialog v-model="excuseDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold">Xin miễn trừ đi muộn</v-toolbar-title>
                    <v-btn icon="mdi-close" :disabled="excuseSubmitting" @click="closeExcuseDialog" />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công: <strong>{{ formatDate(excuseTarget?.attendance_date) }}</strong>,
                        trễ <strong>{{ formatMinutesAsHours(excuseTarget?.late_minutes) }}</strong>.
                        Dùng khi đi muộn có lý do chính đáng (kẹt xe, tai
                        nạn,...) — giờ vào vẫn giữ nguyên, chỉ không tính vào
                        thống kê đi muộn nếu được duyệt.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="excuseForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="excuseErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="excuseGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ excuseGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="excuseSubmitting"
                        @click="submitExcuseRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Ngày 44: giao diện MOBILE của trang Chấm công — toàn bộ state/logic đến
// từ useCheckIn() (dùng CHUNG với CheckInDesktop.vue), file này chỉ còn phần
// hiển thị: 1 cột duy nhất, danh sách thẻ thay bảng nhiều cột, dialog
// fullscreen thay vì dialog nổi (dễ thao tác bằng ngón tay hơn trên màn hình
// hẹp). Xem CheckIn.vue (switcher) và composables/useCheckIn.js.
import { ATTENDANCE_STATUS_MAP, formatDate, formatMinutesAsHours, formatTime, useCheckIn } from "../../composables/useCheckIn";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";

const {
    todayIso,
    todayShifts,
    loadingToday,
    loadError,
    history,
    loadingHistory,
    method,
    qrReference,
    submitting,
    submittingShiftId,
    submitError,
    submit,
    adjustDialog,
    adjustTarget,
    adjustForm,
    adjustErrors,
    adjustGeneralError,
    adjustSubmitting,
    openAdjustDialog,
    closeAdjustDialog,
    submitAdjustRequest,
    supplementDialog,
    supplementForm,
    supplementErrors,
    supplementGeneralError,
    supplementSubmitting,
    shiftOptions,
    openSupplementDialog,
    closeSupplementDialog,
    submitSupplementRequest,
    excuseDialog,
    excuseTarget,
    excuseForm,
    excuseErrors,
    excuseGeneralError,
    excuseSubmitting,
    openExcuseDialog,
    closeExcuseDialog,
    submitExcuseRequest,
} = useCheckIn();
</script>
