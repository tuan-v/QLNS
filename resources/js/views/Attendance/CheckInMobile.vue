<template>
    <div>
        <PageHeader
            title="Chấm công"
            subtitle="Chấm công vào/ra — hệ thống tự ghi nhận IP, vị trí và thiết bị bạn đang dùng."
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
                <v-btn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-plus"
                    block
                    class="md-2"
                    @click="openExtraShiftDialog('extra_shift')"
                >
                    Xin làm ngoài lịch
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

        <!-- Không còn chọn phương thức: mỗi lần bấm Chấm công, hệ thống tự ghi
        IP, vị trí (địa chỉ) và tên thiết bị của chính chiếc điện thoại này. -->
        <v-sheet
            class="border rounded-lg pa-4 mb-4 glass-panel"
            color="transparent"
        >
            <div class="text-body-2" style="opacity: 0.75">
                <v-icon size="small" class="mr-1"
                    >mdi-cellphone-information</v-icon
                >
                Khi bấm Chấm công, hệ thống tự ghi nhận địa chỉ mạng (IP), vị
                trí và tên thiết bị bạn đang dùng. Trình duyệt sẽ hỏi quyền truy
                cập vị trí — nên cho phép để lượt chấm công có địa chỉ.
            </div>

            <div class="text-body-2 font-weight-medium mt-4 mb-1">
                Mã QR tại điểm chấm công (không bắt buộc)
            </div>
            <v-text-field
                v-model="qrReference"
                placeholder="Quét hoặc dán nội dung mã QR nếu nơi làm việc có"
                variant="outlined"
                density="comfortable"
                rounded="lg"
                hide-details
            />

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
                            {{ entry.work_shift.start_time }} -
                            {{ entry.work_shift.end_time }}
                        </div>
                    </div>
                    <div
                        v-if="entry.attendance"
                        class="d-flex flex-column align-end ga-1"
                    >
                        <!-- Gộp trạng thái ca + duyệt công vào 1 chip
                        (2026-09-24, theo yêu cầu người dùng: "khi được duyệt
                        mới được đang trong ca") — xem mergedAttendanceStatus()
                        ở useCheckIn.js. -->
                        <StatusChip
                            :status="mergedAttendanceStatus(entry.attendance)"
                            :map="MERGED_ATTENDANCE_STATUS_MAP"
                        />
                    </div>
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

        <!-- Đơn "Xin làm ngoài lịch"/"Xin OT" của tôi (2026-09-23) — chỉ hiện
        khi có đơn, để không choán chỗ lúc chưa ai dùng tính năng này. -->
        <v-sheet
            v-if="myExtraShiftRequests.length"
            class="border rounded-lg pa-4 mb-4 glass-panel"
            color="transparent"
        >
            <div class="text-subtitle-1 font-weight-bold mb-3">
                Đơn xin làm ngoài lịch / OT của tôi
            </div>
            <div class="d-flex flex-column ga-2">
                <div
                    v-for="item in myExtraShiftRequests"
                    :key="item.id"
                    class="d-flex justify-space-between align-start flex-wrap ga-2 border rounded-lg pa-3"
                >
                    <div>
                        <div class="font-weight-medium">
                            <v-chip
                                size="x-small"
                                variant="tonal"
                                :color="
                                    item.type === 'overtime' ? 'teal' : 'indigo'
                                "
                                class="mr-1"
                            >
                                {{
                                    item.type === "overtime"
                                        ? "OT"
                                        : "Ngoài lịch"
                                }}
                            </v-chip>
                            {{ item.work_shift?.name ?? "—" }} ·
                            {{ formatDate(item.attendance_date) }}
                        </div>
                        <div class="text-caption" style="opacity: 0.7">
                            {{ item.reason }}
                        </div>
                        <div
                            v-if="
                                item.status === 'rejected' && item.decision_note
                            "
                            class="text-caption text-error"
                        >
                            Lý do từ chối: {{ item.decision_note }}
                        </div>
                    </div>
                    <StatusChip
                        :status="item.status"
                        :map="APPROVAL_STATUS_MAP"
                    />
                </div>
            </div>
        </v-sheet>

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
                        <div class="font-weight-bold">
                            {{ formatDate(a.attendance_date) }}
                        </div>
                        <div class="text-body-2" style="opacity: 0.7">
                            {{ a.work_shift?.name ?? "—" }}
                        </div>
                    </div>
                    <div class="d-flex flex-column align-end ga-1">
                        <!-- Gộp trạng thái ca + duyệt công vào 1 chip
                        (2026-09-24, theo yêu cầu người dùng: "khi được duyệt
                        mới được đang trong ca") — chưa duyệt/bị từ chối thì
                        chưa được tính công/lương. -->
                        <StatusChip
                            :status="mergedAttendanceStatus(a)"
                            :map="MERGED_ATTENDANCE_STATUS_MAP"
                        />
                    </div>
                </div>
                <div
                    v-if="a.approval_status === 'rejected' && a.approval_note"
                    class="text-body-2 mb-2"
                    style="opacity: 0.75"
                >
                    Lý do từ chối: {{ a.approval_note }}
                </div>

                <div class="d-flex justify-space-between text-body-2 py-1">
                    <span style="opacity: 0.75">Giờ vào - Giờ ra</span>
                    <span class="font-weight-medium">
                        {{ formatTime(a.first_check_in_at) }} -
                        {{ formatTime(a.last_check_out_at) }}
                    </span>
                </div>

                <div
                    v-if="
                        a.late_minutes ||
                        a.early_leave_minutes ||
                        a.overtime_minutes
                    "
                    class="d-flex flex-wrap ga-2 mt-2"
                >
                    <v-chip
                        v-if="a.late_minutes"
                        size="small"
                        :color="a.late_excused ? 'success' : 'warning'"
                        variant="tonal"
                    >
                        Trễ {{ formatMinutesAsHours(a.late_minutes)
                        }}{{ a.late_excused ? " (đã miễn trừ)" : "" }}
                    </v-chip>
                    <v-chip
                        v-if="a.early_leave_minutes"
                        size="small"
                        color="warning"
                        variant="tonal"
                    >
                        Về sớm {{ formatMinutesAsHours(a.early_leave_minutes) }}
                    </v-chip>
                    <v-chip
                        v-if="a.overtime_minutes"
                        size="small"
                        :color="a.overtime_approved ? 'success' : 'info'"
                        variant="tonal"
                    >
                        OT {{ formatMinutesAsHours(a.overtime_minutes)
                        }}{{ a.overtime_approved ? " (đã duyệt)" : "" }}
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
                    <v-btn
                        v-if="a.overtime_minutes > 0 && !a.overtime_approved"
                        size="small"
                        variant="tonal"
                        color="secondary"
                        rounded="lg"
                        prepend-icon="mdi-clock-plus-outline"
                        @click="openOtApprovalDialog(a)"
                    >
                        Xin duyệt OT
                    </v-btn>
                </div>
            </v-sheet>
        </div>

        <!-- Xin điều chỉnh (correction — sửa 1 bản ghi ĐÃ CÓ) -->
        <v-dialog v-model="adjustDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold"
                        >Xin điều chỉnh công</v-toolbar-title
                    >
                    <v-btn
                        icon="mdi-close"
                        :disabled="adjustSubmitting"
                        @click="closeAdjustDialog"
                    />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công:
                        <strong>{{
                            formatDate(adjustTarget?.attendance_date)
                        }}</strong
                        >. Chỉ cần đề xuất giờ nào bị sai, để trống giờ còn lại
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
                    <v-toolbar-title class="font-weight-bold"
                        >Xin bổ sung chấm công</v-toolbar-title
                    >
                    <v-btn
                        icon="mdi-close"
                        :disabled="supplementSubmitting"
                        @click="closeSupplementDialog"
                    />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Dùng khi bạn quên chấm công cả ngày (không có bản ghi
                        nào để xin điều chỉnh). Phải nhập đủ cả giờ vào lẫn giờ
                        ra.
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

        <!-- Xin làm ngoài lịch / Xin OT (2026-09-23) — 1 dialog, 2 tab (theo
        yêu cầu người dùng): "Làm ngoài lịch" đăng ký TRƯỚC cho 1 ngày/ca
        KHÔNG có trong lịch gán (gộp "làm thêm ngày"/"làm bù T7-CN"), "Xin
        OT" đăng ký TRƯỚC cho OT của 1 ca ĐANG làm hôm nay (tính từ giờ kết
        thúc ca tới lúc chấm công ra). Xem useCheckIn.js. -->
        <v-dialog v-model="extraShiftDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold"
                        >Xin làm ngoài lịch / OT</v-toolbar-title
                    >
                    <v-btn
                        icon="mdi-close"
                        :disabled="extraShiftSubmitting || otRequestSubmitting"
                        @click="closeExtraShiftDialog"
                    />
                </v-toolbar>
                <v-tabs v-model="extraShiftTab" grow>
                    <v-tab value="extra_shift">Làm ngoài lịch</v-tab>
                    <v-tab value="overtime">Xin OT</v-tab>
                </v-tabs>
                <v-window v-model="extraShiftTab">
                    <v-window-item value="extra_shift">
                        <v-card-text
                            style="
                                display: flex;
                                flex-direction: column;
                                gap: 0.75rem;
                            "
                        >
                            <div class="text-body-2" style="opacity: 0.75">
                                Đăng ký TRƯỚC để xin phép làm thêm 1 ngày không
                                có trong lịch của bạn (làm OT cả ngày, làm bù
                                Thứ 7/Chủ nhật…). Sau khi được duyệt, bạn tự bấm
                                Chấm công vào/ra bình thường vào đúng ngày đã
                                đăng ký.
                            </div>

                            <div>
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Ngày muốn làm
                                    <span class="text-error">*</span>
                                </div>
                                <InputDate
                                    v-model="extraShiftForm.attendanceDate"
                                    :min="todayIso()"
                                    :error-messages="
                                        extraShiftErrors.attendance_date
                                    "
                                />
                            </div>

                            <v-btn-toggle
                                v-model="extraShiftForm.mode"
                                mandatory
                                density="comfortable"
                                color="primary"
                                variant="outlined"
                                divided
                            >
                                <v-btn value="existing" size="small"
                                    >Chọn ca có sẵn</v-btn
                                >
                                <v-btn value="custom" size="small"
                                    >Tự chọn giờ</v-btn
                                >
                            </v-btn-toggle>

                            <div v-if="extraShiftForm.mode === 'existing'">
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Ca làm việc
                                    <span class="text-error">*</span>
                                </div>
                                <SearchSelect
                                    v-model="extraShiftForm.workShiftId"
                                    :items="allShiftOptions"
                                    placeholder="Chọn ca làm việc"
                                    :error-messages="
                                        extraShiftErrors.work_shift_id
                                    "
                                />
                            </div>
                            <v-row v-else dense>
                                <v-col cols="6">
                                    <div
                                        class="text-body-2 font-weight-medium mb-1"
                                    >
                                        Giờ bắt đầu
                                        <span class="text-error">*</span>
                                    </div>
                                    <v-text-field
                                        v-model="extraShiftForm.customStartTime"
                                        type="time"
                                        variant="outlined"
                                        density="comfortable"
                                        rounded="lg"
                                        :error-messages="
                                            extraShiftErrors.custom_start_time
                                        "
                                    />
                                </v-col>
                                <v-col cols="6">
                                    <div
                                        class="text-body-2 font-weight-medium mb-1"
                                    >
                                        Giờ kết thúc
                                        <span class="text-error">*</span>
                                    </div>
                                    <v-text-field
                                        v-model="extraShiftForm.customEndTime"
                                        type="time"
                                        variant="outlined"
                                        density="comfortable"
                                        rounded="lg"
                                        :error-messages="
                                            extraShiftErrors.custom_end_time
                                        "
                                    />
                                </v-col>
                            </v-row>

                            <div>
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Lý do <span class="text-error">*</span>
                                </div>
                                <v-textarea
                                    v-model="extraShiftForm.reason"
                                    rows="3"
                                    variant="outlined"
                                    density="comfortable"
                                    rounded="lg"
                                    :error-messages="extraShiftErrors.reason"
                                />
                            </div>

                            <v-alert
                                v-if="extraShiftGeneralError"
                                type="error"
                                variant="tonal"
                                density="compact"
                            >
                                {{ extraShiftGeneralError }}
                            </v-alert>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-btn
                                color="primary"
                                variant="flat"
                                block
                                size="large"
                                :loading="extraShiftSubmitting"
                                @click="submitExtraShiftRequest"
                            >
                                Gửi yêu cầu
                            </v-btn>
                        </v-card-actions>
                    </v-window-item>

                    <v-window-item value="overtime">
                        <v-card-text
                            style="
                                display: flex;
                                flex-direction: column;
                                gap: 0.75rem;
                            "
                        >
                            <div class="text-body-2" style="opacity: 0.75">
                                Đăng ký TRƯỚC cho ca bạn ĐANG làm hôm nay — OT
                                tính từ giờ kết thúc ca tới lúc bạn thực sự chấm
                                công ra, không cần biết trước sẽ làm tới mấy
                                giờ.
                            </div>

                            <div
                                v-if="!otTodayOptions.length"
                                class="text-body-2"
                                style="opacity: 0.6"
                            >
                                Hôm nay bạn chưa chấm công vào ca nào (hoặc đã
                                xin OT hết cho các ca đang có), không có gì để
                                chọn.
                            </div>
                            <div v-else>
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Ca đang làm hôm nay
                                    <span class="text-error">*</span>
                                </div>
                                <SearchSelect
                                    v-model="otRequestForm.attendanceId"
                                    :items="otTodayOptions"
                                    placeholder="Chọn ca"
                                    :error-messages="
                                        otRequestErrors.attendance_id
                                    "
                                />
                            </div>

                            <div>
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Lý do <span class="text-error">*</span>
                                </div>
                                <v-textarea
                                    v-model="otRequestForm.reason"
                                    rows="3"
                                    variant="outlined"
                                    density="comfortable"
                                    rounded="lg"
                                    :error-messages="otRequestErrors.reason"
                                />
                            </div>

                            <v-alert
                                v-if="otRequestGeneralError"
                                type="error"
                                variant="tonal"
                                density="compact"
                            >
                                {{ otRequestGeneralError }}
                            </v-alert>
                        </v-card-text>
                        <v-card-actions class="px-4 pb-4">
                            <v-btn
                                color="primary"
                                variant="flat"
                                block
                                size="large"
                                :disabled="!otTodayOptions.length"
                                :loading="otRequestSubmitting"
                                @click="submitOtRequestFromToday"
                            >
                                Gửi yêu cầu
                            </v-btn>
                        </v-card-actions>
                    </v-window-item>
                </v-window>
            </v-card>
        </v-dialog>

        <!-- Xin miễn trừ đi muộn (excuse — Ngày 42, KHÔNG sửa giờ, chỉ xin
        không tính vào thống kê đi muộn) -->
        <v-dialog v-model="excuseDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold"
                        >Xin miễn trừ đi muộn</v-toolbar-title
                    >
                    <v-btn
                        icon="mdi-close"
                        :disabled="excuseSubmitting"
                        @click="closeExcuseDialog"
                    />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công:
                        <strong>{{
                            formatDate(excuseTarget?.attendance_date)
                        }}</strong
                        >, trễ
                        <strong>{{
                            formatMinutesAsHours(excuseTarget?.late_minutes)
                        }}</strong
                        >. Dùng khi đi muộn có lý do chính đáng (kẹt xe, tai
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

        <!-- Xin duyệt OT (2026-09-21, KHÔNG sửa giờ, chỉ xin HR xác nhận
        phần làm thêm giờ này được trả lương) -->
        <v-dialog v-model="otApprovalDialog" fullscreen persistent>
            <v-card>
                <v-toolbar>
                    <v-toolbar-title class="font-weight-bold"
                        >Xin duyệt OT</v-toolbar-title
                    >
                    <v-btn
                        icon="mdi-close"
                        :disabled="otApprovalSubmitting"
                        @click="closeOtApprovalDialog"
                    />
                </v-toolbar>
                <v-card-text
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công:
                        <strong>{{
                            formatDate(otApprovalTarget?.attendance_date)
                        }}</strong
                        >, làm thêm
                        <strong>{{
                            formatMinutesAsHours(
                                otApprovalTarget?.overtime_minutes,
                            )
                        }}</strong
                        >. Chỉ khi HR duyệt, phần OT này mới được tính vào
                        lương.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="otApprovalForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="otApprovalErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="otApprovalGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ otApprovalGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="otApprovalSubmitting"
                        @click="submitOtApprovalRequest"
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
import {
    APPROVAL_STATUS_MAP,
    MERGED_ATTENDANCE_STATUS_MAP,
    formatDate,
    formatMinutesAsHours,
    formatTime,
    mergedAttendanceStatus,
    useCheckIn,
} from "../../composables/useCheckIn";
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
    otApprovalDialog,
    otApprovalTarget,
    otApprovalForm,
    otApprovalErrors,
    otApprovalGeneralError,
    otApprovalSubmitting,
    openOtApprovalDialog,
    closeOtApprovalDialog,
    submitOtApprovalRequest,
    extraShiftDialog,
    extraShiftTab,
    extraShiftForm,
    extraShiftErrors,
    extraShiftGeneralError,
    extraShiftSubmitting,
    allShiftOptions,
    myExtraShiftRequests,
    openExtraShiftDialog,
    closeExtraShiftDialog,
    submitExtraShiftRequest,
    otTodayOptions,
    otRequestForm,
    otRequestErrors,
    otRequestGeneralError,
    otRequestSubmitting,
    submitOtRequestFromToday,
} = useCheckIn();
</script>
