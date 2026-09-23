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
                    @click="openSupplementDialog"
                >
                    Xin bổ sung chấm công
                </v-btn>
                <v-btn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-plus"
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
        IP, vị trí (địa chỉ) và tên thiết bị của chính thiết bị đang dùng. -->
        <v-sheet
            class="border rounded-lg pa-5 mb-4 glass-panel"
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

        <!-- Trạng thái từng ca hôm nay — 1 nhân viên có thể có nhiều ca cùng
        ngày (mục 14), mỗi ca chấm công độc lập. -->
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
        <v-row v-else dense class="mb-4">
            <v-col
                v-for="entry in todayShifts"
                :key="entry.work_shift.id"
                cols="12"
                md="6"
            >
                <v-sheet
                    class="border rounded-lg pa-5 glass-panel h-100"
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
                            <StatusChip
                                :status="entry.attendance.status"
                                :map="ATTENDANCE_STATUS_MAP"
                            />
                            <StatusChip
                                v-if="entry.attendance.last_check_out_at"
                                :status="entry.attendance.approval_status"
                                :map="APPROVAL_STATUS_MAP"
                            />
                        </div>
                    </div>

                    <div class="d-flex justify-space-between text-body-2 py-1">
                        <span style="opacity: 0.75">Check-in</span>
                        <span class="d-flex align-center font-weight-medium">
                            {{
                                formatTime(entry.attendance?.first_check_in_at)
                            }}
                            <v-icon
                                v-if="entry.attendance?.first_check_in_at"
                                icon="mdi-check-circle"
                                color="success"
                                size="16"
                                class="ml-1"
                            />
                        </span>
                    </div>
                    <div
                        class="d-flex justify-space-between text-body-2 py-1 mb-2"
                    >
                        <span style="opacity: 0.75">Check-out</span>
                        <span class="d-flex align-center font-weight-medium">
                            {{
                                formatTime(entry.attendance?.last_check_out_at)
                            }}
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
                        block
                        :loading="submittingShiftId === entry.work_shift.id"
                        :disabled="submitting"
                        @click="submit(entry.work_shift.id, true)"
                    >
                        Chấm công ra
                    </v-btn>
                </v-sheet>
            </v-col>
        </v-row>

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
                    class="d-flex justify-space-between align-center flex-wrap ga-2 border rounded-lg pa-3"
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

        <!-- Lịch sử gần đây -->
        <div class="d-flex justify-space-between align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">Lịch sử gần đây</div>
            <v-btn
                variant="text"
                color="primary"
                size="small"
                append-icon="mdi-arrow-right"
                :to="{ name: 'attendance-history' }"
            >
                Xem lịch sử đầy đủ
            </v-btn>
        </div>
        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Ca</th>
                        <th>Giờ vào</th>
                        <th>Giờ ra</th>
                        <th>Trễ</th>
                        <th>Về sớm</th>
                        <th>OT</th>
                        <th>Trạng thái</th>
                        <th>Duyệt công</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loadingHistory">
                        <td colspan="10" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!history.length">
                        <td
                            colspan="10"
                            class="text-center py-6"
                            style="opacity: 0.6"
                        >
                            Chưa có lịch sử chấm công.
                        </td>
                    </tr>
                    <tr v-for="a in history" v-else :key="a.id">
                        <td>{{ formatDate(a.attendance_date) }}</td>
                        <td>{{ a.work_shift?.name ?? "—" }}</td>
                        <td>{{ formatTime(a.first_check_in_at) }}</td>
                        <td>{{ formatTime(a.last_check_out_at) }}</td>
                        <td>
                            {{ formatMinutesAsHours(a.late_minutes) }}
                            <span
                                v-if="a.late_minutes && a.late_excused"
                                class="text-success"
                                style="opacity: 0.8"
                            >
                                (đã miễn trừ)
                            </span>
                        </td>
                        <td>
                            {{ formatMinutesAsHours(a.early_leave_minutes) }}
                        </td>
                        <td>
                            {{ formatMinutesAsHours(a.overtime_minutes) }}
                            <span
                                v-if="a.overtime_minutes && a.overtime_approved"
                                class="text-success"
                                style="opacity: 0.8"
                            >
                                (đã duyệt)
                            </span>
                        </td>
                        <td>
                            <StatusChip
                                :status="a.status"
                                :map="ATTENDANCE_STATUS_MAP"
                            />
                        </td>
                        <td>
                            <!-- Chưa duyệt / bị từ chối thì chưa được tính công/lương. -->
                            <StatusChip
                                v-if="a.last_check_out_at"
                                :status="a.approval_status"
                                :map="APPROVAL_STATUS_MAP"
                            />
                            <span v-else style="opacity: 0.5">—</span>
                            <div
                                v-if="
                                    a.approval_status === 'rejected' &&
                                    a.approval_note
                                "
                                class="text-caption mt-1"
                                style="opacity: 0.7; max-width: 180px"
                            >
                                {{ a.approval_note }}
                            </div>
                        </td>
                        <td class="text-center">
                            <div class="d-flex justify-center ga-2">
                                <v-btn
                                    icon="mdi-file-edit-outline"
                                    variant="tonal"
                                    size="small"
                                    rounded="lg"
                                    @click="openAdjustDialog(a)"
                                >
                                    <v-icon icon="mdi-file-edit-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xin điều chỉnh</v-tooltip
                                    >
                                </v-btn>
                                <v-btn
                                    v-if="a.late_minutes > 0 && !a.late_excused"
                                    icon="mdi-shield-check-outline"
                                    variant="tonal"
                                    color="secondary"
                                    size="small"
                                    rounded="lg"
                                    @click="openExcuseDialog(a)"
                                >
                                    <v-icon icon="mdi-shield-check-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xin miễn trừ đi muộn</v-tooltip
                                    >
                                </v-btn>
                                <v-btn
                                    v-if="
                                        a.overtime_minutes > 0 &&
                                        !a.overtime_approved
                                    "
                                    icon="mdi-clock-plus-outline"
                                    variant="tonal"
                                    color="secondary"
                                    size="small"
                                    rounded="lg"
                                    @click="openOtApprovalDialog(a)"
                                >
                                    <v-icon icon="mdi-clock-plus-outline" />
                                    <v-tooltip activator="parent" location="top"
                                        >Xin duyệt OT</v-tooltip
                                    >
                                </v-btn>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <!-- Xin điều chỉnh (correction — sửa 1 bản ghi ĐÃ CÓ) -->
        <v-dialog v-model="adjustDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin điều chỉnh công
                </v-card-title>
                <v-card-text
                    class="px-5"
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
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="adjustSubmitting"
                        @click="closeAdjustDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
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
        <v-dialog v-model="supplementDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin bổ sung chấm công
                </v-card-title>
                <v-card-text
                    class="px-5"
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
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="supplementSubmitting"
                        @click="closeSupplementDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
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
        <v-dialog v-model="extraShiftDialog" max-width="520" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5 pb-0">
                    Xin làm ngoài lịch / OT
                </v-card-title>
                <v-tabs v-model="extraShiftTab" class="px-5 mt-2">
                    <v-tab value="extra_shift">Làm ngoài lịch</v-tab>
                    <v-tab value="overtime">Xin OT</v-tab>
                </v-tabs>
                <v-divider />
                <v-window v-model="extraShiftTab">
                    <v-window-item value="extra_shift">
                        <v-card-text
                            class="px-5 pt-4"
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
                                class="align-self-start"
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
                        <v-card-actions class="px-5 pb-5">
                            <v-spacer />
                            <v-btn
                                variant="text"
                                :disabled="extraShiftSubmitting"
                                @click="closeExtraShiftDialog"
                            >
                                Hủy
                            </v-btn>
                            <v-btn
                                color="primary"
                                variant="flat"
                                :loading="extraShiftSubmitting"
                                @click="submitExtraShiftRequest"
                            >
                                Gửi yêu cầu
                            </v-btn>
                        </v-card-actions>
                    </v-window-item>

                    <v-window-item value="overtime">
                        <v-card-text
                            class="px-5 pt-4"
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
                        <v-card-actions class="px-5 pb-5">
                            <v-spacer />
                            <v-btn
                                variant="text"
                                :disabled="otRequestSubmitting"
                                @click="closeExtraShiftDialog"
                            >
                                Hủy
                            </v-btn>
                            <v-btn
                                color="primary"
                                variant="flat"
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
        <v-dialog v-model="excuseDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin miễn trừ đi muộn
                </v-card-title>
                <v-card-text
                    class="px-5"
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
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="excuseSubmitting"
                        @click="closeExcuseDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
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
        <v-dialog v-model="otApprovalDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin duyệt OT
                </v-card-title>
                <v-card-text
                    class="px-5"
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
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="otApprovalSubmitting"
                        @click="closeOtApprovalDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
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
// Ngày 44: giao diện DESKTOP của trang Chấm công — toàn bộ state/logic đến
// từ useCheckIn() (dùng CHUNG với CheckInMobile.vue), file này chỉ còn phần
// hiển thị (bảng "Lịch sử gần đây" thay vì danh sách thẻ). Xem CheckIn.vue
// (switcher chọn giữa 2 file này theo useDisplay().mobile) và
// composables/useCheckIn.js.
import {
    APPROVAL_STATUS_MAP,
    ATTENDANCE_STATUS_MAP,
    formatDate,
    formatMinutesAsHours,
    formatTime,
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
