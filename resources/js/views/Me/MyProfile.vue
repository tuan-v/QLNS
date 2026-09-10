<template>
    <div>
        <PageHeader
            title="Hồ sơ của tôi"
            subtitle="Thông tin cá nhân, hợp đồng, tài liệu và ca làm việc của bạn."
        />

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
            <!-- Thẻ hồ sơ đầu trang -->
            <v-sheet
                class="border rounded-lg pa-5 mb-4 glass-panel profile-hero"
                color="transparent"
            >
                <div class="d-flex align-center flex-wrap ga-4">
                    <v-avatar size="80" color="surface-variant">
                        <v-img
                            v-if="employee.avatar_url"
                            :src="employee.avatar_url"
                        />
                        <v-icon v-else icon="mdi-account" size="40" />
                    </v-avatar>
                    <div class="flex-grow-1">
                        <div class="text-h6 font-weight-bold">
                            {{ employee.full_name }}
                        </div>
                        <div class="text-body-2" style="opacity: 0.75">
                            {{ employee.position?.name ?? "Chưa xếp chức vụ" }}
                            <template v-if="employee.department">
                                — {{ employee.department.name }}
                            </template>
                        </div>
                        <div class="d-flex align-center ga-2 mt-2 flex-wrap">
                            <StatusChip
                                :status="employee.employment_status"
                                :map="EMPLOYMENT_STATUS_MAP"
                            />
                            <v-chip size="small" variant="tonal" color="default">
                                {{ employee.code }}
                            </v-chip>
                        </div>
                    </div>
                </div>
            </v-sheet>

            <!-- Thẻ thông tin nhanh -->
            <StatCards :stats="quickStats" />

            <v-sheet
                class="border rounded-lg mb-4 mt-4 glass-panel"
                color="transparent"
            >
                <v-tabs v-model="tab">
                    <v-tab value="info">Thông tin cá nhân</v-tab>
                    <v-tab value="contracts">Hợp đồng</v-tab>
                    <v-tab value="documents">Tài liệu</v-tab>
                    <v-tab value="shifts">Ca làm việc</v-tab>
                    <v-tab value="transfers">Luân chuyển</v-tab>
                </v-tabs>
            </v-sheet>

            <v-window v-model="tab">
                <v-window-item value="info">
                    <v-sheet
                        class="border rounded-lg pa-5 glass-panel"
                        color="transparent"
                    >
                        <div class="d-flex justify-end mb-3">
                            <v-btn
                                color="primary"
                                variant="tonal"
                                prepend-icon="mdi-pencil-outline"
                                @click="openEditContactDialog"
                            >
                                Sửa thông tin liên hệ
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
                                        <v-progress-circular indeterminate size="24" />
                                    </td>
                                </tr>
                                <tr v-else-if="!contracts.length">
                                    <td colspan="7" class="text-center py-6" style="opacity: 0.6">
                                        Chưa có hợp đồng nào.
                                    </td>
                                </tr>
                                <tr v-for="contract in contracts" v-else :key="contract.id">
                                    <td>{{ contract.contract_number }}</td>
                                    <td>{{ contract.contract_type }}</td>
                                    <td>{{ formatDate(contract.start_date) }}</td>
                                    <td>{{ formatDate(contract.end_date) ?? "—" }}</td>
                                    <td>{{ formatCurrency(contract.agreed_salary) }}</td>
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
                                                    contract.contract_number + '.pdf',
                                                )
                                            "
                                        >
                                            <v-icon icon="mdi-eye-outline" />
                                            <v-tooltip activator="parent" location="top"
                                                >Xem trước</v-tooltip
                                            >
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
                                    <th class="text-end">Xem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="documentsLoading">
                                    <td colspan="6" class="text-center py-6">
                                        <v-progress-circular indeterminate size="24" />
                                    </td>
                                </tr>
                                <tr v-else-if="!documents.length">
                                    <td colspan="6" class="text-center py-6" style="opacity: 0.6">
                                        Chưa có tài liệu nào.
                                    </td>
                                </tr>
                                <tr v-for="doc in documents" v-else :key="doc.id">
                                    <td>
                                        {{ DOCUMENT_TYPE_MAP[doc.document_type] ?? doc.document_type }}
                                    </td>
                                    <td>{{ doc.document_name }}</td>
                                    <td>{{ formatFileSize(doc.file_size) }}</td>
                                    <td>{{ doc.uploaded_by ?? "—" }}</td>
                                    <td>{{ formatDate(doc.created_at) }}</td>
                                    <td class="text-end">
                                        <v-btn
                                            icon="mdi-eye-outline"
                                            variant="tonal"
                                            size="small"
                                            rounded="lg"
                                            @click="openPreview(doc.download_url, doc.file_name)"
                                        >
                                            <v-icon icon="mdi-eye-outline" />
                                            <v-tooltip activator="parent" location="top"
                                                >Xem trước</v-tooltip
                                            >
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>

                <v-window-item value="shifts">
                    <v-alert
                        v-if="shiftsError"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ shiftsError }}
                    </v-alert>

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
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="shiftsLoading">
                                    <td colspan="5" class="text-center py-6">
                                        <v-progress-circular indeterminate size="24" />
                                    </td>
                                </tr>
                                <tr v-else-if="!shifts.length">
                                    <td colspan="5" class="text-center py-6" style="opacity: 0.6">
                                        Chưa được gán ca làm việc nào.
                                    </td>
                                </tr>
                                <tr v-for="a in shifts" v-else :key="a.id">
                                    <td>
                                        {{ a.work_shift?.name ?? "—" }}
                                        <span style="opacity: 0.5">({{ a.work_shift?.code }})</span>
                                    </td>
                                    <td>{{ formatDate(a.effective_from) }}</td>
                                    <td>{{ a.effective_to ? formatDate(a.effective_to) : "—" }}</td>
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

                    <v-sheet class="border rounded-lg glass-panel" color="transparent">
                        <v-table density="comfortable">
                            <thead>
                                <tr>
                                    <th>Từ phòng ban</th>
                                    <th>Đến phòng ban</th>
                                    <th>Chức vụ mới</th>
                                    <th>Ngày hiệu lực</th>
                                    <th>Lý do</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="transfersLoading">
                                    <td colspan="5" class="text-center py-6">
                                        <v-progress-circular indeterminate size="24" />
                                    </td>
                                </tr>
                                <tr v-else-if="!transfers.length">
                                    <td colspan="5" class="text-center py-6" style="opacity: 0.6">
                                        Chưa có lượt luân chuyển nào.
                                    </td>
                                </tr>
                                <tr v-for="t in transfers" v-else :key="t.id">
                                    <td>{{ t.from_department?.name ?? "—" }}</td>
                                    <td>{{ t.to_department?.name ?? "—" }}</td>
                                    <td>{{ t.new_position?.name ?? "—" }}</td>
                                    <td>{{ formatDate(t.effective_date) }}</td>
                                    <td>{{ t.reason ?? "—" }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-window-item>
            </v-window>
        </template>

        <div v-else-if="loading" class="d-flex justify-center py-10">
            <v-progress-circular indeterminate size="32" />
        </div>

        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />

        <v-dialog v-model="editContactDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Sửa thông tin liên hệ
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Số điện thoại <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="contactForm.phone"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="contactErrors.phone"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Email cá nhân <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="contactForm.personal_email"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="contactErrors.personal_email"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tỉnh/Thành phố <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            :model-value="contactForm.province_code"
                            :items="provinceOptions"
                            :loading="provincesLoading"
                            :error-messages="contactErrors.province_code"
                            @update:model-value="onContactProvinceChange"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Xã/Phường <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="contactForm.commune_code"
                            :items="communeOptions"
                            :loading="communesLoading"
                            :disabled="!contactForm.province_code"
                            :error-messages="contactErrors.commune_code"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Địa chỉ chi tiết <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="contactForm.address_detail"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            placeholder="Số nhà, tên đường..."
                            :error-messages="contactErrors.address_detail"
                        />
                    </div>

                    <v-alert
                        v-if="contactGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ contactGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="contactSubmitting"
                        @click="closeEditContactDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="contactSubmitting"
                        @click="submitContact"
                    >
                        Lưu thay đổi
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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
    </div>
</template>

<script setup>
// Trang tự phục vụ (self-service) — CHÍNH nhân viên xem hồ sơ/hợp đồng/tài
// liệu/ca làm việc của MÌNH, dựng riêng thay vì dùng chung EmployeeDetail.vue
// (khác EmployeeDetail ở 2 điểm): (1) gọi các endpoint /employees/me/... —
// không phụ thuộc employee.view/shift.view (role Employee không có 2 mã
// quyền này, xem CODE_MAP mục 8/14), nên không thể ghép ID rồi điều hướng
// sang route /employees/:id như bản cũ (route đó vẫn đòi employee.view,
// nhân viên thường sẽ bị chặn); (2) gần như chỉ đọc — chỉ 2 việc tự làm được:
// sửa thông tin LIÊN HỆ (không phải toàn bộ hồ sơ, xem UpdateMyProfileRequest
// ở backend) và tự tải tài liệu cá nhân lên. Không có nút Xóa, Tạo tài khoản
// đăng nhập, Sửa Hợp đồng/Ca làm việc... nhân viên thường không cần các thao
// tác quản lý đó cho chính hồ sơ mình — Hợp đồng đặc biệt vẫn chỉ đọc vì đó
// là văn bản pháp lý do HR/Quản lý tạo, không phải nhân viên tự nộp.
import { computed, onMounted, ref, watch } from "vue";
import employeeService from "../../services/employeeService";
import addressService from "../../services/addressService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

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

const DOCUMENT_TYPE_MAP = {
    cccd: "CCCD/CMND",
    resume: "Sơ yếu lý lịch",
    certificate: "Bằng cấp/Chứng chỉ",
    other: "Khác",
};

const WEEK_DAY_LABEL = { 1: "T2", 2: "T3", 3: "T4", 4: "T5", 5: "T6", 6: "T7", 7: "CN" };

function formatWorkDays(days) {
    if (!days?.length) {
        return "—";
    }
    return [...days].sort((a, b) => a - b).map((d) => WEEK_DAY_LABEL[d] ?? d).join(", ");
}

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatCurrency(value) {
    if (value === null || value === undefined) {
        return "—";
    }
    return new Intl.NumberFormat("vi-VN", { style: "currency", currency: "VND" }).format(value);
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

// "2 năm 3 tháng" tính từ ngày vào làm tới hôm nay — chỉ hiển thị, không gửi
// lên API nào nên không cần chính xác tuyệt đối theo lịch âm/dương.
function formatTenure(hireDate) {
    if (!hireDate) {
        return "—";
    }
    const start = new Date(hireDate);
    const now = new Date();
    let months =
        (now.getFullYear() - start.getFullYear()) * 12 + (now.getMonth() - start.getMonth());
    if (now.getDate() < start.getDate()) {
        months -= 1;
    }
    if (months < 0) {
        return "—";
    }
    const years = Math.floor(months / 12);
    const remMonths = months % 12;
    if (years === 0) {
        return `${remMonths} tháng`;
    }
    if (remMonths === 0) {
        return `${years} năm`;
    }
    return `${years} năm ${remMonths} tháng`;
}

const employee = ref(null);
const loading = ref(true);
const loadError = ref("");
const tab = ref("info");

const quickStats = computed(() => {
    if (!employee.value) {
        return [];
    }
    const e = employee.value;
    return [
        {
            label: "Thâm niên",
            value: formatTenure(e.hire_date),
            color: "primary",
            icon: "mdi-clock-star-four-points-outline",
        },
        {
            label: "Ngày vào làm",
            value: formatDate(e.hire_date) ?? "—",
            color: "info",
            icon: "mdi-calendar-check-outline",
        },
        {
            label: "Chức vụ",
            value: e.position?.name ?? "—",
            color: "success",
            icon: "mdi-badge-account-outline",
        },
        {
            label: "Quản lý trực tiếp",
            value: e.manager?.full_name ?? "Không có",
            color: "warning",
            icon: "mdi-account-supervisor-outline",
        },
    ];
});

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
        {
            label: "Địa chỉ",
            value: [e.address_detail, e.commune?.name, e.province?.name]
                .filter(Boolean)
                .join(", ") || null,
        },
        { label: "Phòng ban", value: e.department?.name },
        { label: "Chức vụ", value: e.position?.name },
        { label: "Quản lý trực tiếp", value: e.manager?.full_name },
        { label: "Ngày vào làm", value: formatDate(e.hire_date) },
    ];
});

async function loadProfile() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await employeeService.me();
        employee.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.status === 404
                ? "Tài khoản này chưa được liên kết với hồ sơ nhân viên nào. Liên hệ phòng Nhân sự để được hỗ trợ."
                : "Không thể tải hồ sơ cá nhân, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

/* ----------------------------- Tab: Hợp đồng ----------------------------- */

const contracts = ref([]);
const contractsLoaded = ref(false);
const contractsLoading = ref(false);
const contractsError = ref("");

async function loadContracts() {
    if (contractsLoaded.value) {
        return;
    }
    contractsLoading.value = true;
    contractsError.value = "";
    try {
        const response = await employeeService.myContracts();
        contracts.value = response.data.data;
        contractsLoaded.value = true;
    } catch (e) {
        contractsError.value = e.response?.data?.message ?? "Không thể tải danh sách hợp đồng.";
    } finally {
        contractsLoading.value = false;
    }
}

/* ----------------------------- Tab: Tài liệu ----------------------------- */

const documents = ref([]);
const documentsLoaded = ref(false);
const documentsLoading = ref(false);
const documentsError = ref("");

async function loadDocuments() {
    if (documentsLoaded.value) {
        return;
    }
    documentsLoading.value = true;
    documentsError.value = "";
    try {
        const response = await employeeService.myDocuments();
        documents.value = response.data.data;
        documentsLoaded.value = true;
    } catch (e) {
        documentsError.value = e.response?.data?.message ?? "Không thể tải danh sách tài liệu.";
    } finally {
        documentsLoading.value = false;
    }
}

/* --------------------------- Tab: Ca làm việc --------------------------- */

const shifts = ref([]);
const shiftsLoaded = ref(false);
const shiftsLoading = ref(false);
const shiftsError = ref("");

async function loadShifts() {
    if (shiftsLoaded.value) {
        return;
    }
    shiftsLoading.value = true;
    shiftsError.value = "";
    try {
        const response = await employeeService.myShiftAssignments();
        shifts.value = response.data;
        shiftsLoaded.value = true;
    } catch (e) {
        shiftsError.value = e.response?.data?.message ?? "Không thể tải danh sách ca làm việc.";
    } finally {
        shiftsLoading.value = false;
    }
}

/* --------------------------- Tab: Luân chuyển --------------------------- */

const transfers = ref([]);
const transfersLoaded = ref(false);
const transfersLoading = ref(false);
const transfersError = ref("");

async function loadTransfers() {
    if (transfersLoaded.value) {
        return;
    }
    transfersLoading.value = true;
    transfersError.value = "";
    try {
        const response = await employeeService.myTransfers();
        transfers.value = response.data.data;
        transfersLoaded.value = true;
    } catch (e) {
        transfersError.value = e.response?.data?.message ?? "Không thể tải lịch sử luân chuyển.";
    } finally {
        transfersLoading.value = false;
    }
}

const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });

function openPreview(url, name) {
    previewFile.value = { url, name };
    previewDialog.value = true;
}

// Lazy-load từng tab, giống EmployeeDetail.vue — chỉ gọi API khi người dùng
// thật sự mở tab đó.
watch(tab, (value) => {
    if (value === "contracts") {
        loadContracts();
    }
    if (value === "documents") {
        loadDocuments();
    }
    if (value === "shifts") {
        loadShifts();
    }
    if (value === "transfers") {
        loadTransfers();
    }
});

/* ------------------------ Sửa thông tin liên hệ ------------------------ */

const editContactDialog = ref(false);
const contactForm = ref({
    phone: "",
    personal_email: "",
    address_detail: "",
    province_code: null,
    commune_code: null,
});
const contactErrors = ref({});
const contactGeneralError = ref("");
const contactSubmitting = ref(false);

const provinceOptions = ref([]);
const communeOptions = ref([]);
const provincesLoading = ref(false);
const communesLoading = ref(false);

async function loadProvinces() {
    if (provinceOptions.value.length) {
        return;
    }
    provincesLoading.value = true;
    try {
        const response = await addressService.provinces();
        provinceOptions.value = response.data.map((p) => ({ title: p.name, value: p.code }));
    } finally {
        provincesLoading.value = false;
    }
}

// Xã/Phường phụ thuộc Tỉnh — tải theo từng Tỉnh (giống EmployeeForm.vue),
// không tải hết 3300+ xã cả nước 1 lần.
async function loadCommunes(provinceCode) {
    if (!provinceCode) {
        communeOptions.value = [];
        return;
    }
    communesLoading.value = true;
    try {
        const response = await addressService.communes(provinceCode);
        communeOptions.value = response.data.map((c) => ({ title: c.name, value: c.code }));
    } finally {
        communesLoading.value = false;
    }
}

// Đổi Tỉnh thì Xã đang chọn (thuộc Tỉnh cũ) không còn hợp lệ — chỉ xóa khi
// người dùng THẬT SỰ đổi (qua @update:model-value), không phải lúc mở dialog
// tự điền lại Tỉnh cũ (xem openEditContactDialog()).
function onContactProvinceChange(value) {
    contactForm.value.province_code = value;
    contactForm.value.commune_code = null;
    loadCommunes(value);
}

async function openEditContactDialog() {
    const e = employee.value;
    contactForm.value = {
        phone: e?.phone ?? "",
        personal_email: e?.personal_email ?? "",
        address_detail: e?.address_detail ?? "",
        province_code: e?.province?.code ?? null,
        commune_code: e?.commune?.code ?? null,
    };
    contactErrors.value = {};
    contactGeneralError.value = "";
    editContactDialog.value = true;
    await loadProvinces();
    // Nạp lại Xã theo đúng Tỉnh đã có sẵn — KHÔNG gọi qua onContactProvinceChange()
    // vì hàm đó xóa luôn commune_code, ở đây vừa gán đúng giá trị cũ ở trên.
    loadCommunes(contactForm.value.province_code);
}

function closeEditContactDialog() {
    editContactDialog.value = false;
}

async function submitContact() {
    contactErrors.value = {};
    contactGeneralError.value = "";
    contactSubmitting.value = true;
    try {
        const response = await employeeService.updateMe({ ...contactForm.value });
        employee.value = response.data.data;
        toast.success("Đã cập nhật thông tin liên hệ.");
        closeEditContactDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            contactErrors.value = {
                phone: data.errors.phone?.[0],
                personal_email: data.errors.personal_email?.[0],
                address_detail: data.errors.address_detail?.[0],
                province_code: data.errors.province_code?.[0],
                commune_code: data.errors.commune_code?.[0],
            };
        } else {
            contactGeneralError.value = data?.message ?? "Không thể kết nối máy chủ.";
        }
    } finally {
        contactSubmitting.value = false;
    }
}

/* --------------------------- Tải lên tài liệu --------------------------- */

const documentTypeOptions = Object.entries(DOCUMENT_TYPE_MAP).map(([value, title]) => ({
    title,
    value,
}));

const uploadDialog = ref(false);
const uploadForm = ref({ document_type: null, document_name: "", file: null });
const uploadErrors = ref({});
const uploadGeneralError = ref("");
const uploading = ref(false);

function openUploadDialog() {
    uploadForm.value = { document_type: null, document_name: "", file: null };
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
        formData.append("document_type", uploadForm.value.document_type ?? "");
        formData.append("document_name", uploadForm.value.document_name);
        // v-file-input trả về mảng (kể cả khi multiple=false) — lấy phần tử đầu.
        const file = Array.isArray(uploadForm.value.file)
            ? uploadForm.value.file[0]
            : uploadForm.value.file;
        if (file) {
            formData.append("document_file", file);
        }

        const response = await employeeService.uploadMyDocument(formData);
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
            uploadGeneralError.value = data?.message ?? "Không thể tải lên tài liệu.";
        }
    } finally {
        uploading.value = false;
    }
}

onMounted(() => {
    loadProfile();
});
</script>

<style scoped>
.profile-hero {
    background:
        radial-gradient(circle at 8% 0%, rgba(117, 117, 219, 0.16), transparent 22rem),
        rgba(var(--v-theme-surface), 0.72);
}
</style>
