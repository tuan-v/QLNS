<template>
    <div>
        <PageHeader
            title="Chấm công"
            subtitle="Chấm công vào/ra — hệ thống tự ghi nhận IP, vị trí và thiết bị bạn đang dùng."
        >
            <template #actions>
                <v-btn
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-edit"
                    block
                    @click="openSupplementDialog"
                >
                    Xin bổ sung chấm công
                </v-btn>
                <v-btn
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-plus"
                    block
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

        <!-- Chấm công một chạm: chỉ khi hôm nay có từ 2 ca — chỉ ra đúng ca cần bấm.
        1 ca thì thẻ ca bên dưới đã có sẵn nút, hiện thêm sẽ bị trùng. -->
        <QuickCheckInCard
            v-if="!loadingToday && todayShifts.length > 1"
            :action="quickAction"
            :submitting="submitting"
            :submitting-shift-id="submittingShiftId"
            :block="true"
            @submit="submit"
        />

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
                                :color="REQUEST_TYPE_CHIPS[item.type]?.color ?? 'indigo'"
                                class="mr-1"
                            >
                                {{ REQUEST_TYPE_CHIPS[item.type]?.label ?? "Ngoài lịch" }}
                            </v-chip>
                            {{ requestShiftLabel(item) }} ·
                            {{ formatDateRange(item.attendance_date, item.attendance_date_to) }}
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
                        (a.overtime_minutes && a.overtime_approved)
                    "
                    class="d-flex flex-wrap ga-2 mt-2"
                >
                    <v-chip
                        v-if="a.late_minutes"
                        size="small"
                        color="warning"
                        variant="tonal"
                    >
                        Trễ {{ formatMinutesAsHours(a.late_minutes) }}
                    </v-chip>
                    <v-chip
                        v-if="a.early_leave_minutes"
                        size="small"
                        color="warning"
                        variant="tonal"
                    >
                        Về sớm {{ formatMinutesAsHours(a.early_leave_minutes) }}
                    </v-chip>
                    <!-- Chỉ hiện OT khi đã được duyệt (không xin OT thì để
                    trống, tránh hiểu nhầm là được tính làm thêm giờ). -->
                    <v-chip
                        v-if="a.overtime_minutes && a.overtime_approved"
                        size="small"
                        color="success"
                        variant="tonal"
                    >
                        OT {{ formatMinutesAsHours(a.overtime_minutes) }} (đã
                        duyệt)
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
                        v-if="a.overtime_minutes > 0 && !a.overtime_approved"
                        size="small"
                        variant="tonal"
                        color="primary"
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
                <v-form class="v-card-text" ref="adjustFormRef" validate-on="blur invalid-input lazy" @submit.prevent="validateThen(adjustFormRef, submitAdjustRequest)"
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
 :rules="[(v) => Boolean(v) || Boolean(adjustForm.checkOutTime) || 'Nhập ít nhất giờ vào hoặc giờ ra đúng']"
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
 :rules="[(v) => Boolean(v) || Boolean(adjustForm.checkInTime) || 'Nhập ít nhất giờ vào hoặc giờ ra đúng']"
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
 :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
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
                </v-form>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="adjustSubmitting"
                        @click="validateThen(adjustFormRef, submitAdjustRequest)"
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
                <v-form class="v-card-text" ref="supplementFormRef" validate-on="blur invalid-input lazy" @submit.prevent="validateThen(supplementFormRef, submitSupplementRequest)"
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
 :rules="[notEmpty('Ngày')]"
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
 :rules="[notEmpty('Ca làm việc')]"
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
 :rules="[notEmpty('Giờ vào')]"
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
 :rules="[notEmpty('Giờ ra'), (v) => !v || !supplementForm.checkInTime || v > supplementForm.checkInTime || 'Giờ ra phải sau giờ vào']"
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
 :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
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
                </v-form>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="supplementSubmitting"
                        @click="validateThen(supplementFormRef, submitSupplementRequest)"
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
            <v-card class="dialog-scroll-card">
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
                <v-window v-model="extraShiftTab" class="dialog-scroll-body">
                    <v-window-item value="extra_shift">
                        <v-form class="v-card-text" ref="extraShiftFormRef" validate-on="blur invalid-input lazy" @submit.prevent="validateThen(extraShiftFormRef, submitExtraShiftRequest)"
                            style="
                                display: flex;
                                flex-direction: column;
                                gap: 0.75rem;
                            "
                        >
                            <div class="text-body-2" style="opacity: 0.75">
                                Đăng ký TRƯỚC để xin phép làm thêm 1 ngày không
                                có trong lịch của bạn (làm bù Thứ 7/Chủ nhật…) —
                                tính công như ngày thường. Muốn tính OT thì dùng
                                tab "Xin OT" → "OT ngày khác". Sau khi được duyệt,
                                bạn tự bấm Chấm công vào/ra bình thường vào đúng
                                ngày đã đăng ký.
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
 :rules="[notEmpty('Ngày muốn làm')]"
                                    :min="todayIso()"
                                    :error-messages="
                                        extraShiftErrors.attendance_date
                                    "
                                />
                            </div>
                            <div>
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Đến ngày
                                    <span style="opacity: 0.6"
                                        >(nếu làm nhiều ngày liền nhau)</span
                                    >
                                </div>
                                <InputDate
                                    v-model="extraShiftForm.attendanceDateTo"
                                    :min="
                                        extraShiftForm.attendanceDate ||
                                        todayIso()
                                    "
                                    :rules="[
                                        (v) =>
                                            !v ||
                                            !extraShiftForm.attendanceDate ||
                                            v >= extraShiftForm.attendanceDate ||
                                            'Đến ngày không được trước ngày bắt đầu',
                                    ]"
                                    :error-messages="
                                        extraShiftErrors.attendance_date_to
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
 :rules="[notEmpty('Ca làm việc')]"
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
 :rules="[notEmpty('Giờ bắt đầu')]"
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
 :rules="[notEmpty('Giờ kết thúc'), (v) => !v || !extraShiftForm.customStartTime || v > extraShiftForm.customStartTime || 'Giờ kết thúc phải sau giờ bắt đầu']"
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
 :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
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
                        </v-form>
                        <v-card-actions class="px-4 pb-4">
                            <v-btn
                                color="primary"
                                variant="flat"
                                block
                                size="large"
                                :loading="extraShiftSubmitting"
                                @click="validateThen(extraShiftFormRef, submitExtraShiftRequest)"
                            >
                                Gửi yêu cầu
                            </v-btn>
                        </v-card-actions>
                    </v-window-item>

                    <v-window-item value="overtime">
                        <v-form class="v-card-text" ref="otRequestFormRef" validate-on="blur invalid-input lazy" @submit.prevent="validateThen(otRequestFormRef, submitOtRequestFromToday)"
                            style="
                                display: flex;
                                flex-direction: column;
                                gap: 0.75rem;
                            "
                        >
                            <v-btn-toggle
                                v-model="otRequestForm.mode"
                                mandatory
                                density="comfortable"
                                color="primary"
                                variant="outlined"
                                divided
                                class="align-self-start"
                            >
                                <v-btn value="today" size="small">OT sau ca hôm nay</v-btn>
                                <v-btn value="other_day" size="small">OT ngày khác</v-btn>
                            </v-btn-toggle>

                            <template v-if="otRequestForm.mode === 'other_day'">
                                <div class="text-body-2" style="opacity: 0.75">
                                    Đăng ký TRƯỚC làm OT vào ngày bạn không có ca
                                    (Thứ 7, Chủ nhật, ngày lễ…). Sau khi được duyệt,
                                    bạn chấm công vào/ra bình thường — toàn bộ giờ làm
                                    trong khung đã đăng ký tính là OT (ngày thường
                                    150%, Thứ 7/Chủ nhật 200%, ngày lễ 300%).
                                </div>
                                <div>
                                    <div class="text-body-2 font-weight-medium mb-1">
                                        Ngày làm OT <span class="text-error">*</span>
                                    </div>
                                    <InputDate
                                        v-model="otRequestForm.attendanceDate"
                                        :rules="[notEmpty('Ngày làm OT')]"
                                        :min="todayIso()"
                                        :error-messages="otRequestErrors.attendance_date"
                                    />
                                </div>
                                <v-row dense>
                                    <v-col cols="6">
                                        <div class="text-body-2 font-weight-medium mb-1">
                                            Giờ bắt đầu <span class="text-error">*</span>
                                        </div>
                                        <v-text-field
                                            v-model="otRequestForm.startTime"
                                            :rules="[notEmpty('Giờ bắt đầu')]"
                                            type="time"
                                            variant="outlined"
                                            density="comfortable"
                                            rounded="lg"
                                            :error-messages="otRequestErrors.custom_start_time"
                                        />
                                    </v-col>
                                    <v-col cols="6">
                                        <div class="text-body-2 font-weight-medium mb-1">
                                            Giờ kết thúc <span class="text-error">*</span>
                                        </div>
                                        <v-text-field
                                            v-model="otRequestForm.endTime"
                                            :rules="[
                                                notEmpty('Giờ kết thúc'),
                                                (v) => !otRequestForm.startTime || !v || v > otRequestForm.startTime || 'Giờ kết thúc phải sau giờ bắt đầu',
                                            ]"
                                            type="time"
                                            variant="outlined"
                                            density="comfortable"
                                            rounded="lg"
                                            :error-messages="otRequestErrors.custom_end_time"
                                        />
                                    </v-col>
                                </v-row>
                            </template>

                            <div
                                v-if="otRequestForm.mode === 'today'"
                                class="text-body-2"
                                style="opacity: 0.75"
                            >
                                Đăng ký TRƯỚC cho ca bạn ĐANG làm hôm nay — OT
                                tính từ giờ kết thúc ca tới lúc bạn thực sự chấm
                                công ra, không cần biết trước sẽ làm tới mấy
                                giờ.
                            </div>

                            <div
                                v-if="otRequestForm.mode === 'today' && !otTodayOptions.length"
                                class="text-body-2"
                                style="opacity: 0.6"
                            >
                                Hôm nay bạn chưa chấm công vào ca nào (hoặc đã
                                xin OT hết cho các ca đang có), không có gì để
                                chọn.
                            </div>
                            <div v-else-if="otRequestForm.mode === 'today'">
                                <div
                                    class="text-body-2 font-weight-medium mb-1"
                                >
                                    Ca đang làm hôm nay
                                    <span class="text-error">*</span>
                                </div>
                                <SearchSelect
                                    v-model="otRequestForm.attendanceId"
 :rules="[notEmpty('Ca cần xin OT')]"
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
 :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
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
                        </v-form>
                        <v-card-actions class="px-4 pb-4">
                            <v-btn
                                color="primary"
                                variant="flat"
                                block
                                size="large"
                                :disabled="otRequestForm.mode === 'today' && !otTodayOptions.length"
                                :loading="otRequestSubmitting"
                                @click="validateThen(otRequestFormRef, submitOtRequestFromToday)"
                            >
                                Gửi yêu cầu
                            </v-btn>
                        </v-card-actions>
                    </v-window-item>
                </v-window>
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
                <v-form class="v-card-text" ref="otApprovalFormRef" validate-on="blur invalid-input lazy" @submit.prevent="validateThen(otApprovalFormRef, submitOtApprovalRequest)"
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
 :rules="[notEmpty('Lý do'), maxLength(1000, 'Lý do')]"
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
                </v-form>
                <v-card-actions class="px-4 pb-4">
                    <v-btn
                        color="primary"
                        variant="flat"
                        block
                        size="large"
                        :loading="otApprovalSubmitting"
                        @click="validateThen(otApprovalFormRef, submitOtApprovalRequest)"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref } from "vue";
// Ngày 44: giao diện MOBILE của trang Chấm công — toàn bộ state/logic đến
// từ useCheckIn() (dùng CHUNG với CheckInDesktop.vue), file này chỉ còn phần
// hiển thị: 1 cột duy nhất, danh sách thẻ thay bảng nhiều cột, dialog
// fullscreen thay vì dialog nổi (dễ thao tác bằng ngón tay hơn trên màn hình
// hẹp). Xem CheckIn.vue (switcher) và composables/useCheckIn.js.
import {
    APPROVAL_STATUS_MAP,
    MERGED_ATTENDANCE_STATUS_MAP,
    formatDate,
    formatDateRange,
    formatMinutesAsHours,
    formatTime,
    mergedAttendanceStatus,
    REQUEST_TYPE_CHIPS,
    requestShiftLabel,
    useCheckIn,
} from "../../composables/useCheckIn";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import QuickCheckInCard from "../../components/attendance/QuickCheckInCard.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import { maxLength, notEmpty, validateThen } from "../../composables/validationRules";
import { useRouteAction } from "../../composables/useRouteAction";

const adjustFormRef = ref(null);
const supplementFormRef = ref(null);
const extraShiftFormRef = ref(null);
const otRequestFormRef = ref(null);
const otApprovalFormRef = ref(null);

const {
    todayIso,
    todayShifts,
    quickAction,
    loadingToday,
    loadError,
    history,
    loadingHistory,
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

// Mở thẳng thao tác khi vào trang bằng ?action=... (lệnh Ctrl+K).
useRouteAction({
    supplement: openSupplementDialog,
    "extra-shift": () => openExtraShiftDialog("extra_shift"),
    ot: () => openExtraShiftDialog("overtime"),
});

</script>
