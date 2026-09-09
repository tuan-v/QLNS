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
            <v-sheet
                class="border rounded-lg mb-4 glass-panel"
                color="transparent"
            >
                <v-tabs v-model="tab">
                    <v-tab value="profile">Sơ yếu lý lịch</v-tab>
                    <v-tab value="contracts">Hợp đồng</v-tab>
                    <v-tab value="documents">Tài liệu</v-tab>
                    <v-tab value="transfers">Luân chuyển</v-tab>
                    <v-tab value="shift_assignments">Ca làm việc</v-tab>
                    <v-tab value="payroll">Lương / Phép</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="profile">
                    <v-sheet
                        class="border rounded-lg pa-5 glass-panel"
                        color="transparent"
                    >
                        <div
                            class="d-flex align-center justify-space-between flex-wrap ga-4 mb-5"
                        >
                            <div class="d-flex align-center ga-4">
                                <v-avatar size="72" color="surface-variant">
                                    <v-img
                                        v-if="employee.avatar_url"
                                        :src="employee.avatar_url"
                                    />
                                    <v-icon v-else icon="mdi-account" size="36" />
                                </v-avatar>
                                <div>
                                    <div class="text-h6 font-weight-bold">
                                        {{ employee.full_name }}
                                    </div>
                                    <StatusChip
                                        :status="employee.employment_status"
                                        :map="EMPLOYMENT_STATUS_MAP"
                                    />
                                </div>
                            </div>

                            <!-- Tài khoản đăng nhập: hiện thông tin nếu đã có
                                 (email + Role), hoặc nút tạo nếu chưa có — xem
                                 EmployeeAccountController. -->
                            <div v-if="employee.user" class="text-end">
                                <div class="text-caption" style="opacity: 0.6">
                                    Tài khoản đăng nhập
                                </div>
                                <div class="text-body-2 font-weight-medium">
                                    {{ employee.user.email }}
                                </div>
                                <div class="d-flex ga-1 flex-wrap justify-end mt-1">
                                    <v-chip
                                        v-for="roleName in employee.user.roles"
                                        :key="roleName"
                                        size="x-small"
                                        variant="tonal"
                                        color="primary"
                                    >
                                        {{ roleName }}
                                    </v-chip>
                                </div>
                            </div>
                            <v-btn
                                v-else-if="canUpdate"
                                size="small"
                                variant="tonal"
                                color="primary"
                                prepend-icon="mdi-account-key-outline"
                                @click="openAccountDialog"
                            >
                                Tạo tài khoản đăng nhập
                            </v-btn>
                        </div>

                        <v-row dense>
                            <v-col
                                v-for="field in profileFields"
                                :key="field.label"
                                cols="12"
                                sm="6"
                                md="4"
                            >
                                <div class="text-caption" style="opacity: 0.6">
                                    {{ field.label }}
                                </div>
                                <div class="text-body-2 font-weight-medium">
                                    {{ field.value ?? "—" }}
                                </div>
                            </v-col>
                        </v-row>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="contracts">
                    <v-alert
                        v-if="contractsError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ contractsError }}
                    </v-alert>

                    <v-sheet
                        class="border rounded-lg glass-panel"
                        color="transparent"
                    >
                        <v-table density="comfortable">
                            <thead>
                                <tr>
                                    <th>Số hợp đồng</th>
                                    <th>Loại HĐ</th>
                                    <th>Bắt đầu</th>
                                    <th>Kết thúc</th>
                                    <th>Lương thỏa thuận</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="contractsLoading">
                                    <td colspan="7" class="text-center py-6">
                                        <v-progress-circular
                                            indeterminate
                                            size="24"
                                        />
                                    </td>
                                </tr>
                                <tr v-else-if="!contracts.length">
                                    <td
                                        colspan="7"
                                        class="text-center py-6"
                                        style="opacity: 0.6"
                                    >
                                        Chưa có hợp đồng nào.
                                    </td>
                                </tr>
                                <tr
                                    v-for="contract in contracts"
                                    v-else
                                    :key="contract.id"
                                >
                                    <td>{{ contract.contract_number }}</td>
                                    <td>{{ contract.contract_type }}</td>
                                    <td>
                                        {{ formatDate(contract.start_date) }}
                                    </td>
                                    <td>
                                        {{
                                            formatDate(contract.end_date) ?? "—"
                                        }}
                                    </td>
                                    <td>
                                        {{
                                            formatCurrency(
                                                contract.agreed_salary,
                                            )
                                        }}
                                    </td>
                                    <td>
                                        <StatusChip
                                            :status="contract.status"
                                            :map="CONTRACT_STATUS_MAP"
                                        />
                                    </td>

                                    <td class="text-end">
                                        <v-btn
                                            icon="mdi-eye-outline"
                                            variant="tonal"
                                            size="small"
                                            rounded="lg"
                                            @click="
                                                openPreview(
                                                    contract.download_url,
                                                    contract.contract_number +
                                                        '.pdf',
                                                )
                                            "
                                        >
                                            <v-icon icon="mdi-eye-outline" />
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                                >Xem trước</v-tooltip
                                            >
                                        </v-btn>

                                        <v-btn
                                            icon="mdi-download-outline"
                                            variant="tonal"
                                            size="small"
                                            rounded="lg"
                                            :loading="
                                                downloadingId === contract.id
                                            "
                                            @click="downloadContract(contract)"
                                        >
                                            <v-icon
                                                icon="mdi-download-outline"
                                            />
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                            >
                                                Tải file
                                            </v-tooltip>
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="documents">
                    <v-alert
                        v-if="documentsError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ documentsError }}
                    </v-alert>

                    <div class="d-flex justify-end mb-3">
                        <v-btn
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-upload-outline"
                            @click="openUploadDialog"
                        >
                            Tải lên tài liệu
                        </v-btn>
                    </div>

                    <v-sheet
                        class="border rounded-lg glass-panel"
                        color="transparent"
                    >
                        <v-table density="comfortable">
                            <thead>
                                <tr>
                                    <th>Loại tài liệu</th>
                                    <th>Tên tài liệu</th>
                                    <th>Kích thước</th>
                                    <th>Người tải lên</th>
                                    <th>Ngày tải lên</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="documentsLoading">
                                    <td colspan="6" class="text-center py-6">
                                        <v-progress-circular
                                            indeterminate
                                            size="24"
                                        />
                                    </td>
                                </tr>
                                <tr v-else-if="!documents.length">
                                    <td
                                        colspan="6"
                                        class="text-center py-6"
                                        style="opacity: 0.6"
                                    >
                                        Chưa có tài liệu nào.
                                    </td>
                                </tr>
                                <tr
                                    v-for="doc in documents"
                                    v-else
                                    :key="doc.id"
                                >
                                    <td>
                                        {{
                                            DOCUMENT_TYPE_MAP[
                                                doc.document_type
                                            ] ?? doc.document_type
                                        }}
                                    </td>
                                    <td>{{ doc.document_name }}</td>
                                    <td>{{ formatFileSize(doc.file_size) }}</td>
                                    <td>{{ doc.uploaded_by ?? "—" }}</td>
                                    <td>{{ formatDate(doc.created_at) }}</td>
                                    <td class="text-end">
                                        <div class="d-flex justify-end ga-2">
                                            <v-btn
                                                icon="mdi-eye-outline"
                                                variant="tonal"
                                                size="small"
                                                rounded="lg"
                                                @click="
                                                    openPreview(
                                                        doc.download_url,
                                                        doc.file_name,
                                                    )
                                                "
                                            >
                                                <v-icon
                                                    icon="mdi-eye-outline"
                                                />
                                                <v-tooltip
                                                    activator="parent"
                                                    location="top"
                                                    >Xem trước</v-tooltip
                                                >
                                            </v-btn>

                                            <v-btn
                                                icon="mdi-download-outline"
                                                variant="tonal"
                                                size="small"
                                                rounded="lg"
                                                :loading="
                                                    downloadingDocumentId ===
                                                    doc.id
                                                "
                                                @click="downloadDocument(doc)"
                                            >
                                                <v-icon
                                                    icon="mdi-download-outline"
                                                />
                                                <v-tooltip
                                                    activator="parent"
                                                    location="top"
                                                >
                                                    Tải file
                                                </v-tooltip>
                                            </v-btn>
                                            <v-btn
                                                icon="mdi-delete-outline"
                                                variant="tonal"
                                                color="error"
                                                size="small"
                                                rounded="lg"
                                                @click="
                                                    confirmDeleteDocument(doc)
                                                "
                                            >
                                                <v-icon
                                                    icon="mdi-delete-outline"
                                                />
                                                <v-tooltip
                                                    activator="parent"
                                                    location="top"
                                                >
                                                    Xóa
                                                </v-tooltip>
                                            </v-btn>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="transfers">
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

                    <v-sheet
                        class="border rounded-lg glass-panel"
                        color="transparent"
                    >
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
                                        <v-progress-circular
                                            indeterminate
                                            size="24"
                                        />
                                    </td>
                                </tr>
                                <tr v-else-if="!transfers.length">
                                    <td
                                        colspan="7"
                                        class="text-center py-6"
                                        style="opacity: 0.6"
                                    >
                                        Chưa có lượt luân chuyển nào.
                                    </td>
                                </tr>
                                <tr v-for="t in transfers" v-else :key="t.id">
                                    <td>
                                        {{ t.from_department?.name ?? "—" }}
                                    </td>
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
                                                openPreview(
                                                    t.decision_file_url,
                                                    'quyet-dinh' +
                                                        t.id +
                                                        '.pdf',
                                                )
                                            "
                                        >
                                            <v-icon icon="mdi-eye-outline" />
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                                >Xem trước</v-tooltip
                                            >
                                        </v-btn>

                                        <v-btn
                                            v-if="t.decision_file_url"
                                            icon="mdi-download-outline"
                                            variant="tonal"
                                            size="small"
                                            rounded="lg"
                                            :loading="
                                                downloadingTransferId === t.id
                                            "
                                            @click="downloadTransferDecision(t)"
                                        >
                                            <v-icon
                                                icon="mdi-download-outline"
                                            />
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                            >
                                                Tải quyết định
                                            </v-tooltip>
                                        </v-btn>
                                        <span v-else style="opacity: 0.4"
                                            >—</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="shift_assignments">
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

                    <v-sheet
                        class="border rounded-lg glass-panel"
                        color="transparent"
                    >
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
                                        <v-progress-circular
                                            indeterminate
                                            size="24"
                                        />
                                    </td>
                                </tr>
                                <tr v-else-if="!shiftAssignments.length">
                                    <td
                                        colspan="6"
                                        class="text-center py-6"
                                        style="opacity: 0.6"
                                    >
                                        Chưa gán ca làm việc nào.
                                    </td>
                                </tr>
                                <tr
                                    v-for="a in shiftAssignments"
                                    v-else
                                    :key="a.id"
                                >
                                    <td>
                                        {{ a.work_shift?.name ?? "—" }}
                                        <span style="opacity: 0.5">
                                            ({{ a.work_shift?.code }})
                                        </span>
                                    </td>
                                    <td>{{ formatDate(a.effective_from) }}</td>
                                    <td>
                                        {{
                                            a.effective_to
                                                ? formatDate(a.effective_to)
                                                : "—"
                                        }}
                                    </td>
                                    <td>{{ formatWorkDays(a.work_days) }}</td>
                                    <td>
                                        <v-chip
                                            size="small"
                                            variant="tonal"
                                            :color="
                                                a.status === 'active'
                                                    ? 'success'
                                                    : 'default'
                                            "
                                        >
                                            {{
                                                a.status === "active"
                                                    ? "Đang áp dụng"
                                                    : "Đã kết thúc"
                                            }}
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
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                                >Sửa</v-tooltip
                                            >
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
                                            <v-tooltip
                                                activator="parent"
                                                location="top"
                                                >Xóa</v-tooltip
                                            >
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="payroll">
                    <v-alert
                        type="info"
                        variant="tonal"
                        icon="mdi-information-outline"
                    >
                        Chưa triển khai — Lương thuộc Phase 4 (Ngày 46+), Nghỉ
                        phép thuộc Phase 3 (Ngày 36+) theo kế hoạch dự án.
                    </v-alert>
                </v-window-item>
            </v-window>
        </template>

        <v-dialog v-model="uploadDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Tải lên tài liệu
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại tài liệu <span class="text-error">*</span>
                        </div>
                        <v-select
                            v-model="uploadForm.document_type"
                            :items="documentTypeOptions"
                            placeholder="Chưa chọn"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            persistent-placeholder
                            :error-messages="uploadErrors.document_type"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tên tài liệu <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="uploadForm.document_name"
                            placeholder="Ví dụ: CCCD mặt trước"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="uploadErrors.document_name"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tệp đính kèm <span class="text-error">*</span>
                        </div>
                        <v-file-input
                            v-model="uploadForm.file"
                            placeholder="Chọn PDF, Word (.docx), Excel (.xlsx), JPG hoặc PNG (tối đa 10MB)"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                            :error-messages="uploadErrors.document_file"
                        />
                    </div>

                    <v-alert
                        v-if="uploadGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ uploadGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="uploading"
                        @click="closeUploadDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="uploading"
                        @click="submitUpload"
                    >
                        Tải lên
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do
                        </div>
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

        <v-dialog v-model="deleteDialog" max-width="420">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xóa tài liệu
                </v-card-title>
                <v-card-text class="px-5">
                    Bạn có chắc muốn xóa tài liệu
                    <strong>{{ deletingDocument?.document_name }}</strong>
                    không?
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="deletingSubmitting"
                        @click="deleteDialog = false"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="deletingSubmitting"
                        @click="submitDeleteDocument"
                    >
                        Xóa
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="accountDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Tạo tài khoản đăng nhập
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Email đăng nhập: <strong>{{ employee?.company_email }}</strong>.
                        Mật khẩu đặt qua email gửi cho nhân viên, không hiển
                        thị ở đây.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Role <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="accountRoleIds"
                            :items="accountRoleOptions"
                            :error-messages="accountErrors.role_ids"
                            multiple
                            chips
                            closable-chips
                        />
                    </div>

                    <v-alert
                        v-if="accountGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ accountGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="accountSubmitting"
                        @click="closeAccountDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="accountSubmitting"
                        @click="submitAccount"
                    >
                        Tạo tài khoản
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from "vue";
import { useRouter } from "vue-router";
import employeeService from "../../services/employeeService";
import departmentService from "../../services/departmentService";
import positionService from "../../services/positionService";
import roleService from "../../services/roleService";
import workShiftService from "../../services/workShiftService";
import { useAuthStore } from "../../stores/authStore";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
const props = defineProps({
    id: {
        type: String,
        required: true,
    },
});

const router = useRouter();
const toast = useToastStore();
const auth = useAuthStore();

const canUpdate = computed(() => auth.permissions.includes("employee.update"));

const DOCUMENT_TYPE_MAP = {
    cccd: "CCCD/CMND",
    resume: "Sơ yếu lý lịch",
    certificate: "Bằng cấp/Chứng chỉ",
    other: "Khác",
};
const documentTypeOptions = Object.entries(DOCUMENT_TYPE_MAP).map(
    ([value, title]) => ({ title, value }),
);

const EMPLOYMENT_STATUS_MAP = {
    probation: { label: "Thử việc", color: "warning" },
    active: { label: "Đang làm việc", color: "success" },
    resigned: { label: "Đã nghỉ việc", color: "default" },
    terminated: { label: "Đã chấm dứt HĐ", color: "error" },
};

const GENDER_MAP = {
    male: "Nam",
    female: "Nữ",
    other: "Khác",
};

const CONTRACT_STATUS_MAP = {
    active: { label: "Còn hiệu lực", color: "success" },
    expired: { label: "Hết hạn", color: "default" },
    terminated: { label: "Đã chấm dứt", color: "error" },
};

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

// Gộp 3 phần (chi tiết, Xã, Tỉnh) thành 1 dòng hiển thị — trả về null (không
// phải chuỗi rỗng) khi không có gì để field.value ?? "—" ở template hiện
// đúng dấu gạch ngang thay vì để trống trơn.
function formatAddress(e) {
    const parts = [e.address_detail, e.commune?.name, e.province?.name].filter(
        Boolean,
    );
    return parts.length ? parts.join(", ") : null;
}

function formatCurrency(value) {
    if (value === null || value === undefined) {
        return "—";
    }
    return new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(value);
}

function formatFileSize(bytes) {
    if (!bytes) {
        return "—";
    }
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ------------------------------- Tab 1: Hồ sơ ------------------------------ */

const employee = ref(null);
const loadError = ref("");
const tab = ref("profile");

const profileFields = computed(() => {
    if (!employee.value) {
        return [];
    }
    const e = employee.value;
    return [
        { label: "Ngày sinh", value: formatDate(e.date_of_birth) },
        { label: "Giới tính", value: GENDER_MAP[e.gender] },
        { label: "Điện thoại", value: e.phone },
        { label: "Email công ty", value: e.company_email },
        { label: "Email cá nhân", value: e.personal_email },
        { label: "CCCD", value: e.cccd },
        { label: "Mã số thuế cá nhân", value: e.personal_tax_code },
        { label: "Địa chỉ", value: formatAddress(e) },
        { label: "Phòng ban", value: e.department?.name },
        { label: "Chức vụ", value: e.position?.name },
        { label: "Quản lý trực tiếp", value: e.manager?.full_name },
        { label: "Ngày vào làm", value: formatDate(e.hire_date) },
    ];
});

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

/* ----------------------------- Tab 2: Hợp đồng ----------------------------- */

const contracts = ref([]);
const contractsLoaded = ref(false);
const contractsLoading = ref(false);
const contractsError = ref("");
const downloadingId = ref(null);
const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });

function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

async function loadContracts() {
    if (contractsLoaded.value) {
        return;
    }
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.contracts(props.id);
        contracts.value = response.data.data;
        contractsLoaded.value = true;
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

// Chỉ gọi API hợp đồng khi người dùng thật sự mở Tab 2, tránh gọi thừa nếu
// họ chỉ xem Sơ yếu lý lịch rồi rời trang — giống tinh thần tối ưu N+1 ở
// Ngày 25, áp dụng ở mức "đừng gọi API khi chưa cần".
watch(tab, (value) => {
    if (value === "contracts") {
        loadContracts();
    }
    if (value === "documents") {
        loadDocuments();
    }
    if (value === "transfers") {
        loadTransfers();
    }
    if (value === "shift_assignments") {
        loadShiftAssignments();
    }
});

async function downloadContract(contract) {
    downloadingId.value = contract.id;
    contractsError.value = "";
    try {
        // download_url trỏ tới route yêu cầu auth:api — dùng axios (tự gắn
        // Authorization header qua interceptor ở bootstrap.js) thay vì thẻ <a>
        // trần, vì thẻ <a> không gửi kèm header nên sẽ bị 401.
        const response = await window.axios.get(contract.download_url, {
            responseType: "blob",
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.download = `${contract.contract_number}.pdf`;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        contractsError.value =
            e.response?.data?.message ?? "Không thể tải file hợp đồng.";
    } finally {
        downloadingId.value = null;
    }
}

/* ----------------------------- Tab 3: Tài liệu ----------------------------- */

const documents = ref([]);
const documentsLoaded = ref(false);
const documentsLoading = ref(false);
const documentsError = ref("");
const downloadingDocumentId = ref(null);

async function loadDocuments() {
    if (documentsLoaded.value) {
        return;
    }
    documentsLoading.value = true;
    documentsError.value = "";
    try {
        const response = await employeeService.documents(props.id);
        documents.value = response.data.data;
        documentsLoaded.value = true;
    } catch (e) {
        documentsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách tài liệu.";
    } finally {
        documentsLoading.value = false;
    }
}

async function downloadDocument(doc) {
    downloadingDocumentId.value = doc.id;
    documentsError.value = "";
    try {
        const response = await window.axios.get(doc.download_url, {
            responseType: "blob",
        });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.download = doc.file_name;
        link.click();
        window.URL.revokeObjectURL(url);
    } catch (e) {
        documentsError.value =
            e.response?.data?.message ?? "Không thể tải tệp tài liệu.";
    } finally {
        downloadingDocumentId.value = null;
    }
}

// --- Tải lên ---

const uploadDialog = ref(false);
const uploadForm = reactive({
    document_type: null,
    document_name: "",
    file: null,
});
const uploadErrors = ref({});
const uploadGeneralError = ref("");
const uploading = ref(false);

function openUploadDialog() {
    uploadForm.document_type = null;
    uploadForm.document_name = "";
    uploadForm.file = null;
    uploadErrors.value = {};
    uploadGeneralError.value = "";
    uploadDialog.value = true;
}

function closeUploadDialog() {
    uploadDialog.value = false;
}

async function submitUpload() {
    uploadErrors.value = {};
    uploadGeneralError.value = "";
    uploading.value = true;
    try {
        const formData = new FormData();
        formData.append("document_type", uploadForm.document_type ?? "");
        formData.append("document_name", uploadForm.document_name);
        // v-file-input trả về mảng (kể cả khi multiple=false) — lấy phần tử đầu.
        const file = Array.isArray(uploadForm.file)
            ? uploadForm.file[0]
            : uploadForm.file;
        if (file) {
            formData.append("document_file", file);
        }

        const response = await employeeService.uploadDocument(
            props.id,
            formData,
        );
        documents.value = [response.data.data, ...documents.value];
        toast.success("Đã tải lên tài liệu.");
        closeUploadDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            uploadErrors.value = {
                document_type: data.errors.document_type?.[0],
                document_name: data.errors.document_name?.[0],
                document_file: data.errors.document_file?.[0],
            };
        } else {
            uploadGeneralError.value =
                data?.message ?? "Không thể tải lên tài liệu.";
        }
    } finally {
        uploading.value = false;
    }
}

// --- Xóa ---

const deleteDialog = ref(false);
const deletingDocument = ref(null);
const deletingSubmitting = ref(false);

function confirmDeleteDocument(doc) {
    deletingDocument.value = doc;
    deleteDialog.value = true;
}

async function submitDeleteDocument() {
    deletingSubmitting.value = true;
    try {
        await employeeService.deleteDocument(
            props.id,
            deletingDocument.value.id,
        );
        documents.value = documents.value.filter(
            (doc) => doc.id !== deletingDocument.value.id,
        );
        toast.success("Đã xóa tài liệu.");
        deleteDialog.value = false;
    } catch (e) {
        documentsError.value =
            e.response?.data?.message ?? "Không thể xóa tài liệu.";
        deleteDialog.value = false;
    } finally {
        deletingSubmitting.value = false;
    }
}

/* ---------------------------- Tab 4: Luân chuyển --------------------------- */

const transfers = ref([]);
const transfersLoaded = ref(false);
const transfersLoading = ref(false);
const transfersError = ref("");
const downloadingTransferId = ref(null);

async function loadTransfers() {
    if (transfersLoaded.value) {
        return;
    }
    transfersLoading.value = true;
    transfersError.value = "";
    try {
        const response = await employeeService.transfers(props.id);
        transfers.value = response.data.data;
        transfersLoaded.value = true;
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

// --- Dialog tạo luân chuyển ---

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

// Loại phòng ban hiện tại của nhân viên ra khỏi lựa chọn — backend chặn 422
// nếu chọn trùng (xem EmployeeTransferService::create()), nhưng để lọt lên
// dropdown vẫn chọn được thì người dùng phải đợi hết 1 vòng submit mới biết
// sai, lọc thẳng ở đây đỡ tốn round-trip đó.
const transferDepartmentOptions = computed(() =>
    flattenDepartments(allDepartments.value)
        .filter((dept) => dept.id !== employee.value?.department?.id)
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
        .filter((e) => String(e.id) !== String(props.id))
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
// tải sẵn lúc mount trang.
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
        formData.append(
            "to_department_id",
            transferForm.to_department_id ?? "",
        );
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

        const response = await employeeService.createTransfer(
            props.id,
            formData,
        );
        transfers.value = [response.data.data, ...transfers.value];
        toast.success("Đã tạo luân chuyển — hồ sơ nhân viên đã cập nhật.");
        closeTransferDialog();
        // Phòng ban/chức vụ/quản lý vừa đổi — nạp lại tab Sơ yếu lý lịch cho
        // khớp, tránh hiện thông tin cũ nếu người dùng quay lại tab đó.
        loadEmployee();
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
            transferGeneralError.value =
                data?.message ?? "Không thể tạo luân chuyển.";
        }
    } finally {
        transferSubmitting.value = false;
    }
}

/* ------------------------- Tạo tài khoản đăng nhập ------------------------ */

const accountDialog = ref(false);
const accountRoleIds = ref([]);
const accountRoleOptions = ref([]);
const accountErrors = ref({});
const accountGeneralError = ref("");
const accountSubmitting = ref(false);

async function loadAccountRoleOptions() {
    const response = await roleService.list();
    accountRoleOptions.value = response.data.map((role) => ({
        title: role.name,
        value: role.id,
    }));
}

// Tự điền gợi ý Role theo Chức vụ hiện tại của nhân viên (employee.position.
// suggested_roles — xem PositionRepository::paginate()), sửa được trước khi
// xác nhận. PHẢI đợi accountRoleOptions tải xong rồi mới gán accountRoleIds —
// gán trước khi v-autocomplete có đủ items để đối chiếu khiến chip hiện
// nhầm ra ID thô ("3") thay vì tên Role ("Manager"), vì Vuetify chỉ dựng
// nhãn hiển thị của lựa chọn có sẵn tại đúng thời điểm model-value đổi.
async function openAccountDialog() {
    accountErrors.value = {};
    accountGeneralError.value = "";
    accountRoleIds.value = [];
    accountDialog.value = true;
    await loadAccountRoleOptions();
    accountRoleIds.value =
        employee.value?.position?.suggested_roles?.map((r) => r.id) ?? [];
}

function closeAccountDialog() {
    accountDialog.value = false;
}

async function submitAccount() {
    accountErrors.value = {};
    accountGeneralError.value = "";

    if (!accountRoleIds.value.length) {
        accountErrors.value = {
            role_ids: "Chọn ít nhất 1 Role cho tài khoản.",
        };
        return;
    }

    accountSubmitting.value = true;
    try {
        await employeeService.createAccount(props.id, {
            role_ids: accountRoleIds.value,
        });
        toast.success(
            "Đã tạo tài khoản đăng nhập, email đặt mật khẩu đã được gửi.",
        );
        closeAccountDialog();
        // Nạp lại hồ sơ để employee.user hiện đúng tài khoản vừa tạo, ẩn nút
        // "Tạo tài khoản đăng nhập" đi (chỉ hiện khi chưa có tài khoản).
        loadEmployee();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            accountErrors.value = { role_ids: data.errors.role_ids?.[0] };
            accountGeneralError.value = data.errors.employee?.[0] ?? "";
        } else {
            accountGeneralError.value =
                data?.message ?? "Không thể tạo tài khoản.";
        }
    } finally {
        accountSubmitting.value = false;
    }
}

/* --------------------------- Tab 5: Ca làm việc --------------------------- */

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
const shiftAssignmentsLoaded = ref(false);
const shiftAssignmentsLoading = ref(false);
const shiftAssignmentsError = ref("");
const deletingAssignmentId = ref(null);

async function loadShiftAssignments() {
    if (shiftAssignmentsLoaded.value) {
        return;
    }
    shiftAssignmentsLoading.value = true;
    shiftAssignmentsError.value = "";
    try {
        const response = await employeeService.shiftAssignments(props.id);
        shiftAssignments.value = response.data;
        shiftAssignmentsLoaded.value = true;
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
        await employeeService.deleteShiftAssignment(props.id, assignment.id);
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
                  props.id,
                  editingAssignment.value.id,
                  payload,
              )
            : await employeeService.createShiftAssignment(props.id, payload);

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
            assignShiftGeneralError.value =
                data?.message ?? "Không thể kết nối máy chủ.";
        }
    } finally {
        assignShiftSubmitting.value = false;
    }
}

onMounted(() => {
    loadEmployee();
});
</script>
