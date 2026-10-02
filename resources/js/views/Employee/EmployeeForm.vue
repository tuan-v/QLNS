<template>
    <FormDialog
        :validation="false"
        :model-value="modelValue"
        eyebrow="HỒ SƠ NHÂN VIÊN"
        :title="isEdit ? 'Sửa nhân viên' : 'Thêm nhân viên'"
        :subtitle="
            isEdit
                ? 'Cập nhật thông tin nhân viên.'
                : 'Tạo hồ sơ nhân viên mới.'
        "
        :error="store.loadError"
        :loading="store.loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        max-width="760"
        @update:model-value="close"
        @submit="submit"
    >
        <v-form ref="formRef" validate-on="blur invalid-input lazy">
            <FormSection title="Thông tin cơ bản">
                <v-row dense>
                    <v-col cols="12" sm="7">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Họ tên <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.full_name"
                            :rules="rules.fullName"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            placeholder="Nhập họ tên"
                            :error-messages="store.errors.full_name"
                        />
                    </v-col>

                    <v-col cols="12" sm="5">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Mã nhân viên
                        </div>
                        <v-text-field
                            :model-value="
                                isEdit ? employee.code : 'Tự động sau khi lưu'
                            "
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            readonly
                            :class="{ 'text-medium-emphasis': !isEdit }"
                        />
                    </v-col>

                    <v-col cols="12" sm="7">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Email công ty <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.company_email"
                            :rules="rules.companyEmail"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            placeholder="ten@congty.com"
                            :error-messages="fieldErrors('company_email')"
                            @blur="checkUnique('company_email')"
                        />
                    </v-col>

                    <v-col cols="12" sm="5">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày vào làm <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="form.hire_date"
                            :rules="rules.hireDate"
                            :error-messages="store.errors.hire_date"
                        />
                    </v-col>
                </v-row>
            </FormSection>

            <FormSection title="Thông tin cá nhân">
                <v-row dense>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày sinh <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="form.date_of_birth"
                            :rules="rules.dateOfBirth"
                            :max="maxBirthDate"
                            :error-messages="store.errors.date_of_birth"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Giới tính <span class="text-error">*</span>
                        </div>
                        <v-select
                            v-model="form.gender"
                            :rules="rules.gender"
                            :items="genderOptions"
                            placeholder="Chưa chọn"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            clearable
                            persistent-placeholder
                            :error-messages="store.errors.gender"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Điện thoại <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.phone"
                            :rules="rules.phone"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="fieldErrors('phone')"
                            @blur="checkUnique('phone')"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Email cá nhân <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.personal_email"
                            :rules="rules.personalEmail"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="fieldErrors('personal_email')"
                            @blur="checkUnique('personal_email')"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Căn cước công dân <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.cccd"
                            :rules="rules.cccd"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="fieldErrors('cccd')"
                            @blur="checkUnique('cccd')"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Mã số thuế cá nhân <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.personal_tax_code"
                            :rules="rules.personalTaxCode"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="fieldErrors('personal_tax_code')"
                            @blur="checkUnique('personal_tax_code')"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tỉnh/Thành phố <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            :model-value="form.province_code"
                            :rules="rules.provinceCode"
                            :items="provinceOptions"
                            :loading="provincesLoading"
                            clearable
                            :error-messages="store.errors.province_code"
                            @update:model-value="onProvinceChange"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Xã/Phường <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="form.commune_code"
                            :rules="rules.communeCode"
                            :items="communeOptions"
                            :loading="communesLoading"
                            :disabled="!form.province_code"
                            clearable
                            :error-messages="store.errors.commune_code"
                        />
                    </v-col>

                    <v-col cols="12">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Địa chỉ chi tiết <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.address_detail"
                            :rules="rules.addressDetail"
                            placeholder="Số nhà, tên đường..."
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="store.errors.address_detail"
                        />
                    </v-col>
                </v-row>
            </FormSection>

            <FormSection title="Tổ chức">
                <v-row dense>
                    <v-col cols="12" sm="6">
                        <div
                            class="d-flex align-center justify-space-between mb-1"
                        >
                            <span class="text-body-2 font-weight-medium">
                                Phòng ban <span class="text-error">*</span>
                            </span>
                            <v-btn
                                v-if="canManageOrg"
                                variant="text"
                                size="x-small"
                                density="comfortable"
                                prepend-icon="mdi-plus"
                                class="px-1"
                                @click="quickDepartmentOpen = true"
                            >
                                Tạo nhanh
                            </v-btn>
                        </div>
                        <SearchSelect
                            :model-value="form.department_id"
                            :rules="rules.departmentId"
                            :items="departmentOptions"
                            clearable
                            :error-messages="store.errors.department_id"
                            @update:model-value="onDepartmentChange"
                        />
                    </v-col>

                    <v-col cols="12" sm="6">
                        <div
                            class="d-flex align-center justify-space-between mb-1"
                        >
                            <span class="text-body-2 font-weight-medium">
                                Chức vụ
                            </span>
                            <v-btn
                                v-if="canManageOrg"
                                variant="text"
                                size="x-small"
                                density="comfortable"
                                prepend-icon="mdi-plus"
                                class="px-1"
                                @click="quickPositionOpen = true"
                            >
                                Tạo nhanh
                            </v-btn>
                        </div>
                        <SearchSelect
                            :model-value="form.position_id"
                            :items="positionOptions"
                            clearable
                            :error-messages="store.errors.position_id"
                            @update:model-value="onPositionChange"
                        />
                    </v-col>

                    <!-- Quản lý trực tiếp KHÔNG chọn tay (2026-09-29, theo yêu cầu
                     người dùng) — luôn là Trưởng phòng của phòng ban, Backend tự
                     tính (ReportingLineService); ô này chỉ xem trước kết quả. -->
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Quản lý trực tiếp
                        </div>
                        <v-text-field
                            :model-value="predictedManagerName"
                            readonly
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-inner-icon="mdi-account-tie-outline"
                            hint="Tự động là Trưởng phòng của phòng ban"
                            persistent-hint
                        />
                    </v-col>

                    <!-- Trạng thái nhân viên KHÔNG chọn tay (2026-09-29) — thêm mới thì
                     theo "Loại hợp đồng" ở mục bên dưới; sau đó tự đổi theo hợp
                     đồng mới/chấm dứt hợp đồng/đơn nghỉ việc. -->
                    <v-col v-if="isEdit" cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Trạng thái nhân viên
                        </div>
                        <div
                            class="d-flex align-center flex-wrap ga-2"
                            style="min-height: 48px"
                        >
                            <StatusChip
                                :status="employee?.employment_status"
                                :map="EMPLOYMENT_STATUS_MAP"
                            />
                            <span
                                v-if="employee?.termination_date"
                                class="text-body-2 text-medium-emphasis"
                            >
                                từ {{ formatDateVi(employee.termination_date) }}
                            </span>
                        </div>
                        <div class="text-caption text-medium-emphasis">
                            Tự đổi theo hợp đồng (tab "Hợp đồng") và đơn nghỉ
                            việc.
                        </div>
                    </v-col>

                    <v-col
                        v-if="
                            isEdit
                                ? employee?.employment_status === 'probation'
                                : contractType === 'thu_viec'
                        "
                        cols="12"
                        sm="6"
                    >
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày kết thúc thử việc
                        </div>
                        <InputDate
                            v-model="form.probation_end_date"
                            :min="minAfterHireDate"
                            :error-messages="store.errors.probation_end_date"
                        />
                    </v-col>
                </v-row>
            </FormSection>

            <!-- Chỉ hiện lúc Thêm mới (2026-09-24, theo yêu cầu người dùng) — Hợp
             đồng lao động ĐẦU TIÊN tự tạo LUÔN cùng lúc, không cần thao tác
             riêng ở tab "Hợp đồng" nữa (EmployeeService::create()). Muốn ký
             hợp đồng MỚI sau này (hết thử việc, tăng lương...) vẫn qua tab
             "Hợp đồng" ở trang chi tiết nhân viên như cũ — form này KHÔNG
             sửa được lương của hợp đồng đã tạo. Chỉ 1 ô lương duy nhất
             (2026-09-24, theo yêu cầu người dùng: "lương đóng bh sẽ tính là
             lương cb luôn không tách ra") — không hỏi riêng "Lương đóng
             BHXH" nữa, backend tự đặt bằng đúng Lương cơ bản
             (EmployeeContractService::create()), áp dụng luôn cho cả tab
             "Hợp đồng" (EmployeeContractsTab.vue). -->
            <FormSection v-if="!isEdit" title="Lương & Hợp đồng">
                <p class="text-body-2 mb-4" style="opacity: 0.75">
                    Lưu là tạo luôn hợp đồng lao động đầu tiên (bắt đầu từ ngày
                    vào làm) với loại hợp đồng và mức lương này (cũng là lương
                    đóng BHXH). Trạng thái nhân viên đặt theo đúng loại hợp
                    đồng: "Thử việc" hoặc "Chính thức". File hợp đồng đã ký
                    upload sau ở tab "Hợp đồng".
                </p>
                <v-row dense>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại hợp đồng <span class="text-error">*</span>
                        </div>
                        <v-select
                            v-model="contractType"
                            :items="CONTRACT_TYPE_OPTIONS"
                            :rules="rules.contractType"
                            placeholder="Chọn loại hợp đồng"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            persistent-placeholder
                            :error-messages="store.errors.contract_type"
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lương cơ bản <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model.number="agreedSalary"
                            :rules="rules.agreedSalary"
                            type="number"
                            min="0"
                            suffix="đ"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="store.errors.agreed_salary"
                        />
                    </v-col>
                </v-row>
            </FormSection>

            <!-- Chỉ hiện lúc Thêm mới — sửa nhân viên đã có/chưa có tài khoản thì
             dùng nút "Tạo tài khoản đăng nhập" riêng ở EmployeeDetail.vue. -->
            <FormSection v-if="!isEdit" title="Tài khoản đăng nhập">
                <v-checkbox
                    v-model="createAccount"
                    label="Tạo tài khoản đăng nhập ngay sau khi lưu"
                    density="comfortable"
                    hide-details
                />
                <template v-if="createAccount">
                    <div class="text-body-2 font-weight-medium mb-1 mt-3">
                        Role <span class="text-error">*</span>
                    </div>
                    <SearchSelect
                        v-model="accountRoleIds"
                        :items="roleOptions"
                        multiple
                        chips
                        closable-chips
                        :error-messages="accountError"
                    />
                    <div class="text-caption mt-1" style="opacity: 0.65">
                        Tự điền theo Chức vụ đang chọn, sửa được. Mật khẩu gửi
                        qua email cho nhân viên tự đặt, không hiển thị ở đây.
                    </div>
                </template>
            </FormSection>
        </v-form>

        <!-- Tạo nhanh Phòng ban / Chức vụ ngay trong form nhân viên. Dùng lại
             đúng 2 form của trang Phòng ban và Chức vụ, KHÔNG viết form rút gọn
             riêng: viết lại sẽ có 2 bộ validation và 2 bộ field phải nhớ đồng bộ
             mỗi lần nghiệp vụ đổi. Dialog lồng trong dialog là hợp lệ với Vuetify
             vì nội dung được teleport ra ngoài, không nằm trong DOM của nhau. -->
        <DepartmentFormDialog
            v-model="quickDepartmentOpen"
            :department="null"
            :parent-options="departmentOptions"
            @saved="onQuickDepartmentSaved"
        />

        <PositionFormDialog
            v-model="quickPositionOpen"
            :position="null"
            :department-options="positionDepartmentOptions"
            :default-department-id="form.department_id"
            @saved="onQuickPositionSaved"
        />

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import employeeService from "../../services/employeeService";
import StatusChip from "../../components/common/StatusChip.vue";
import {
    CONTRACT_TYPE_OPTIONS,
    EMPLOYMENT_STATUS_MAP,
} from "../../composables/employmentStatus";
import positionService from "../../services/positionService";
import addressService from "../../services/addressService";
import roleService from "../../services/roleService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";
import { useChangeGuard } from "../../composables/useChangeGuard";
import {
    isEmail,
    maxLength,
    minValue,
    notEmpty,
} from "../../composables/validationRules";
import { useAuthStore } from "../../stores/authStore";
import DepartmentFormDialog from "../Department/DepartmentForm.vue";
import PositionFormDialog from "../Position/PositionForm.vue";
import InputDate, {
    shiftIsoDate,
    todayIso,
} from "../../components/common/InputDate.vue";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    // null = thêm mới, object = sửa nhân viên đang chọn
    employee: {
        type: Object,
        default: null,
    },
    // { title, value } — dùng lại đúng danh sách phòng ban đã làm phẳng của
    // Employees.vue, tránh gọi API cây phòng ban 2 lần trên cùng 1 trang.
    departmentOptions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const store = useEmployeeStore();
const toast = useToastStore();
const guard = useChangeGuard(() => form);

const isEdit = computed(() => props.employee !== null);

const genderOptions = [
    { title: "Nam", value: "male" },
    { title: "Nữ", value: "female" },
    { title: "Khác", value: "other" },
];

const form = reactive({
    full_name: "",
    company_email: "",
    hire_date: "",
    date_of_birth: "",
    gender: null,
    phone: "",
    personal_email: "",
    cccd: "",
    personal_tax_code: "",
    address_detail: "",
    province_code: null,
    commune_code: null,
    department_id: null,
    position_id: null,
    probation_end_date: "",
});

const formRef = ref(null);

/* -------------------- Lương & Hợp đồng (chỉ lúc Thêm mới) ------------------- */

// Tách riêng khỏi `form` (không phải field của Employee) — cùng cách
// createAccount/accountRoleIds ở dưới xử lý phần "chỉ áp dụng lúc Thêm mới",
// tránh gửi nhầm lên UpdateEmployeeRequest (backend tự bỏ qua field lạ,
// nhưng tách riêng cho rõ ràng là 2 việc khác nhau).
const agreedSalary = ref(null);
// Loại hợp đồng đầu tiên — quyết định luôn trạng thái nhân viên (2026-09-29).
const contractType = ref(null);

/* --------------------- Tạo tài khoản đăng nhập ngay --------------------- */

const createAccount = ref(false);
const accountRoleIds = ref([]);
const accountError = ref("");
const roleOptions = ref([]);

// Giữ lại promise của lần loadRoles() gần nhất để applySuggestedRoles() luôn
// đợi roleOptions tải xong rồi mới gán accountRoleIds — gán trước khi
// v-autocomplete có đủ items để đối chiếu khiến chip hiện nhầm ra ID thô
// ("3") thay vì tên Role ("Manager"), lỗi đã vấp thật ở EmployeeDetail.vue.
let rolesLoadPromise = Promise.resolve();

function loadRoles() {
    rolesLoadPromise = roleService.list().then((response) => {
        roleOptions.value = response.data.map((role) => ({
            title: role.name,
            value: role.id,
        }));
    });
    return rolesLoadPromise;
}

async function applySuggestedRoles(positionId) {
    await rolesLoadPromise;
    const position = allPositions.value.find((p) => p.id === positionId);
    accountRoleIds.value = position?.suggested_roles?.map((r) => r.id) ?? [];
}

// Bật checkbox lúc đã chọn sẵn Chức vụ (thứ tự: chọn Chức vụ trước, tick sau)
// thì cũng tự điền gợi ý luôn — onPositionChange() ở dưới chỉ lo chiều ngược
// lại (đổi Chức vụ trong lúc checkbox đã bật).
watch(createAccount, (enabled) => {
    if (!enabled) {
        return;
    }
    applySuggestedRoles(form.position_id);
});

// Rule phía client phản chiếu đúng Form Request của backend. Mục đích là báo lỗi
// ngay khi người dùng rời ô, thay vì phải bấm Lưu rồi chờ 422 — backend vẫn là
// nơi kiểm tra cuối cùng. Sửa rule ở backend thì phải sửa cả ở đây.
const rules = {
    fullName: [notEmpty("Tên nhân viên"), maxLength(255, "Tên nhân viên")],
    companyEmail: [notEmpty("Email công ty"), isEmail("Email công ty")],
    hireDate: [notEmpty("Ngày vào làm")],
    dateOfBirth: [notEmpty("Ngày sinh")],
    gender: [notEmpty("Giới tính")],
    phone: [
        notEmpty("Số điện thoại"),
        (value) =>
            !value ||
            /^\d{10}$/.test(String(value)) ||
            "Số điện thoại phải gồm đúng 10 chữ số",
    ],
    personalEmail: [notEmpty("Email cá nhân"), isEmail("Email cá nhân")],
    cccd: [
        notEmpty("Số căn cước công dân"),
        (value) =>
            /^\d{12}$/.test(String(value ?? "")) ||
            "Số căn cước công dân phải là 12 chữ số",
    ],
    personalTaxCode: [
        notEmpty("Mã số thuế cá nhân"),
        maxLength(20, "Mã số thuế cá nhân"),
    ],
    addressDetail: [
        notEmpty("Địa chỉ chi tiết"),
        maxLength(255, "Địa chỉ chi tiết"),
    ],
    provinceCode: [notEmpty("Tỉnh/Thành phố")],
    communeCode: [notEmpty("Xã/Phường")],
    departmentId: [notEmpty("Phòng ban")],
    agreedSalary: [notEmpty("Lương cơ bản"), minValue(0, "Lương cơ bản")],
    contractType: [notEmpty("Loại hợp đồng")],
};

/* ------------- Kiểm tra tức thì (không đợi bấm Lưu) — 2026-09-30 ------------- */

// Các ô phải là DUY NHẤT trong công ty — chỉ Backend biết có trùng hay không nên
// hỏi API ngay khi rời ô (sau khi đúng định dạng), không đợi tới lúc Lưu.
const UNIQUE_FIELDS = {
    company_email: { label: "Email công ty", rule: () => rules.companyEmail },
    personal_email: { label: "Email cá nhân", rule: () => rules.personalEmail },
    phone: { label: "Số điện thoại", rule: () => rules.phone },
    cccd: { label: "Số căn cước công dân", rule: () => rules.cccd },
    personal_tax_code: {
        label: "Mã số thuế cá nhân",
        rule: () => rules.personalTaxCode,
    },
};
const uniqueErrors = reactive({});

// Lỗi hiển thị dưới ô: lỗi 422 của lần Lưu trước + lỗi "trùng" vừa kiểm tra.
function fieldErrors(key) {
    return [
        ...(store.errors[key] ?? []),
        ...(uniqueErrors[key] ? [uniqueErrors[key]] : []),
    ];
}

async function checkUnique(key) {
    const value = String(form[key] ?? "").trim();
    const { label, rule } = UNIQUE_FIELDS[key];
    uniqueErrors[key] = null;

    // Sai định dạng thì để rule của ô báo trước, chưa cần hỏi Backend.
    if (!value || rule().some((check) => check(value) !== true)) {
        return;
    }

    try {
        const response = await employeeService.checkUnique({
            field: key,
            value,
            ignore_id: props.employee?.id,
        });
        // Người dùng đã sửa tiếp trong lúc chờ -> bỏ qua kết quả cũ.
        if (
            String(form[key] ?? "").trim() === value &&
            !response.data.available
        ) {
            uniqueErrors[key] =
                `${label} này đã được dùng cho một nhân viên khác`;
        }
    } catch {
        // Kiểm tra sớm chỉ là tiện lợi — lỗi mạng thì để lúc Lưu Backend báo.
    }
}

// Sửa ô nào thì lỗi cũ (422 lần Lưu trước / báo trùng) của đúng ô đó tự biến mất.
for (const key of Object.keys(form)) {
    watch(
        () => form[key],
        () => {
            delete store.errors[key];
            uniqueErrors[key] = null;
        },
    );
}

// Giới hạn của picker phản chiếu đúng rule trong StoreEmployeeRequest /
// UpdateEmployeeRequest, để người dùng không chọn được ngày mà backend chắc
// chắn trả 422. Backend vẫn là nơi kiểm tra cuối cùng, đây chỉ là chặn sớm.
//
// date_of_birth: 'before:today' -> ngày muộn nhất chọn được là hôm qua.
const maxBirthDate = computed(() => shiftIsoDate(todayIso(), -1));
// probation_end_date: 'after:hire_date' -> sớm nhất là
// ngày kế tiếp ngày vào làm. Chưa nhập ngày vào làm thì không giới hạn.
const minAfterHireDate = computed(() => shiftIsoDate(form.hire_date, 1));

function fillForm() {
    const e = props.employee;
    form.full_name = e?.full_name ?? "";
    form.company_email = e?.company_email ?? "";
    form.hire_date = toDateInput(e?.hire_date);
    form.date_of_birth = toDateInput(e?.date_of_birth);
    form.gender = e?.gender ?? null;
    form.phone = e?.phone ?? "";
    form.personal_email = e?.personal_email ?? "";
    form.cccd = e?.cccd ?? "";
    form.personal_tax_code = e?.personal_tax_code ?? "";
    form.address_detail = e?.address_detail ?? "";
    form.province_code = e?.province?.code ?? null;
    form.commune_code = e?.commune?.code ?? null;
    form.department_id = e?.department?.id ?? null;
    form.position_id = e?.position?.id ?? null;
    form.probation_end_date = toDateInput(e?.probation_end_date);
}

function formatDateVi(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "";
}

// API trả ngày dạng ISO datetime ("2021-05-09T17:00:00.000000Z") — chuẩn hóa
// ngay khi nạp để state của form luôn là "YYYY-MM-DD", đúng dạng gửi lại lên
// API kể cả khi người dùng không sửa ô ngày nào.
function toDateInput(value) {
    if (!value) {
        return "";
    }
    return value.slice(0, 10);
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            store.resetErrors();
            Object.keys(uniqueErrors).forEach(
                (key) => delete uniqueErrors[key],
            );
            fillForm();
            guard.takeSnapshot();
            // Xóa lỗi đỏ còn sót của lần mở trước, tránh form vừa mở đã báo lỗi.
            formRef.value?.resetValidation();
            // Tài khoản đăng nhập chỉ áp dụng lúc Thêm mới — reset lại mỗi lần
            // mở modal, không giữ trạng thái tick của lần thêm trước.
            createAccount.value = false;
            accountRoleIds.value = [];
            accountError.value = "";
            loadRoles();
            // Lương & Hợp đồng cũng chỉ áp dụng lúc Thêm mới — cùng lý do reset
            // như createAccount ở trên, không giữ số cũ của lần thêm trước.
            agreedSalary.value = null;
            contractType.value = null;
            // Nạp lại danh sách Xã/Phường đúng theo Tỉnh đã có sẵn (modal Sửa) —
            // KHÔNG gọi qua onProvinceChange() vì hàm đó xóa luôn commune_code,
            // ở đây form.commune_code vừa được fillForm() gán đúng giá trị cũ.
            loadCommunes(form.province_code);
            // Nạp lại Chức vụ mỗi lần mở modal (không chỉ onMounted) — form này
            // là component thường trực, không mount lại theo mỗi lần mở.
            loadPositions();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    // Chặn ngay ở client nếu còn ô chưa hợp lệ — v-form tự cuộn tới ô lỗi đầu tiên.
    const { valid } = await formRef.value.validate();

    // Đang có ô bị báo trùng (kiểm tra lúc rời ô) — không gửi lên cho tốn 1 vòng 422.
    if (!valid || Object.values(uniqueErrors).some(Boolean)) {
        return;
    }

    if (isEdit.value && guard.skipIfUnchanged()) {
        close();
        return;
    }

    accountError.value = "";
    if (createAccount.value && accountRoleIds.value.length === 0) {
        accountError.value = "Chọn ít nhất 1 Role cho tài khoản.";
        return;
    }

    // Chuỗi rỗng gửi lên backend cho field "nullable" sẽ bị coi là có giá trị
    // (không phải null) — ví dụ date "" không qua nổi rule "nullable|date".
    // Đổi rỗng thành null trước khi gửi để đúng ý "chưa nhập".
    const payload = Object.fromEntries(
        Object.entries(form).map(([key, value]) => [
            key,
            value === "" ? null : value,
        ]),
    );

    // Lương & Hợp đồng chỉ gửi lúc Thêm mới — Hợp đồng ĐẦU TIÊN tự tạo cùng
    // lúc (2026-09-24), sửa nhân viên không tự sửa được lương hợp đồng qua
    // form này (vẫn phải qua tab "Hợp đồng" để ký hợp đồng MỚI).
    if (!isEdit.value) {
        payload.agreed_salary = agreedSalary.value;
        payload.contract_type = contractType.value;
    }

    try {
        if (isEdit.value) {
            await store.update(props.employee.id, payload);
            toast.success("Đã cập nhật nhân viên.");
        } else {
            const created = await store.create(payload);
            toast.success("Đã thêm nhân viên mới.");

            if (createAccount.value) {
                await createAccountForNewEmployee(created.data.id);
            }
        }
        emit("saved");
        close();
    } catch {
        // Lỗi đã được store xử lý (422 -> store.errors, còn lại -> store.loadError),
        // giữ modal mở để người dùng sửa lại dữ liệu.
    }
}

// Tách riêng khỏi luồng chính: hồ sơ nhân viên ĐÃ tạo thành công tại thời
// điểm này (không thể/không nên "hoàn tác" chỉ vì bước tạo tài khoản lỗi) —
// lỗi ở đây chỉ báo toast riêng, không throw ra ngoài để submit() vẫn đóng
// modal + coi như đã lưu xong, người dùng tạo lại tài khoản sau ở trang chi
// tiết nhân viên (nút riêng, xem EmployeeDetail.vue) nếu bước này thất bại.
async function createAccountForNewEmployee(employeeId) {
    try {
        await employeeService.createAccount(employeeId, {
            role_ids: accountRoleIds.value,
        });
        toast.success(
            "Đã tạo tài khoản đăng nhập, email đặt mật khẩu đã được gửi.",
        );
    } catch (e) {
        const message =
            e.response?.data?.errors?.role_ids?.[0] ??
            e.response?.data?.errors?.employee?.[0] ??
            e.response?.data?.message ??
            "Không thể kết nối máy chủ.";
        toast.error(
            `Đã thêm nhân viên nhưng tạo tài khoản đăng nhập thất bại: ${message} Bạn có thể tạo lại ở trang chi tiết nhân viên.`,
        );
    }
}

/* ------------------- Chức vụ: lọc theo phòng ban đang chọn ------------------ */

const allPositions = ref([]);

async function loadPositions() {
    const response = await positionService.list({ per_page: 1000 });
    allPositions.value = response.data.data;
}

const positionOptions = computed(() =>
    allPositions.value
        .filter(
            (position) =>
                !form.department_id ||
                position.department_id === form.department_id,
        )
        .map((position) => ({ title: position.name, value: position.id })),
);

// Đổi phòng ban thì chức vụ đang chọn (thuộc phòng ban cũ) không còn hợp lệ
// nữa — chỉ xóa khi người dùng THẬT SỰ đổi (qua @update:model-value), không
// xóa lúc fillForm() tự gán department_id khi mở modal Sửa.
function onDepartmentChange(value) {
    form.department_id = value;
    form.position_id = null;
}

// Đổi Chức vụ thì tự điền lại gợi ý Role tương ứng (position.suggested_roles,
// xem PositionRepository::paginate()) vào ô chọn Role của mục "Tạo tài khoản
// đăng nhập" — chỉ khi checkbox đang bật, và chỉ khi người dùng THẬT SỰ đổi
// (qua @update:model-value), cùng lý do onDepartmentChange() ở trên không
// dùng watch() chung.
function onPositionChange(value) {
    form.position_id = value;

    if (!createAccount.value) {
        return;
    }

    applySuggestedRoles(value);
}

/* ------------------ Tạo nhanh Phòng ban / Chức vụ tại chỗ ----------------- */

// Thiếu một phòng ban hoặc chức vụ giữa chừng thì trước đây phải thoát form ra
// trang khác để thêm — mà form này là modal, thoát ra là mất trắng dữ liệu đang
// gõ dở. Tạo nhanh tại chỗ rồi gán luôn vào ô đang chọn.
const auth = useAuthStore();

// Cả tạo phòng ban lẫn tạo chức vụ đều dùng chung quyền `department.manage`
// (xem routes/api/v1/departments.php và positions.php) — không có quyền thì ẩn
// nút đi, để bấm vào rồi mới nhận 403 là trải nghiệm tồi.
const canManageOrg = computed(() =>
    auth.permissions.includes("department.manage"),
);

const quickDepartmentOpen = ref(false);
const quickPositionOpen = ref(false);

// PositionForm khai báo `item-value="id"` — trang Chức vụ dùng quy ước danh sách
// { title, id }, khác với phần còn lại của dự án (kể cả prop departmentOptions
// của chính form này) đang dùng quy ước mặc định của Vuetify là { title, value }.
// Truyền thẳng sang thì Vuetify dò khóa `id` không thấy, không khớp được phòng
// ban đang chọn với mục nào và in ra id thô (vd "10") thay vì tên phòng ban.
// Đổi khóa ngay tại chỗ gọi thay vì sửa PositionForm: trang Chức vụ đang chạy
// đúng với quy ước cũ, đổi bên đó là phải sửa lan sang cả bộ lọc của Positions.vue.
const positionDepartmentOptions = computed(() =>
    props.departmentOptions.map((option) => ({
        title: option.title,
        id: option.value,
    })),
);

function onQuickDepartmentSaved(created) {
    if (!created?.id) {
        return;
    }

    // Danh sách phòng ban tự làm mới: useDepartmentStore.create() đã gọi
    // fetchTree(), mà departmentOptions của Employees.vue tính từ chính store đó.
    // Gọi lại onDepartmentChange() thay vì gán thẳng để giữ đúng quy tắc "đổi
    // phòng ban thì bỏ chức vụ đang chọn" — phòng ban vừa tạo chưa có chức vụ nào.
    onDepartmentChange(created.id);
}

async function onQuickPositionSaved(created) {
    if (!created?.id) {
        return;
    }

    // Ô Chức vụ lọc từ allPositions nên phải nạp lại, không thì chức vụ vừa tạo
    // không có trong danh sách và giá trị gán vào sẽ hiển thị trống.
    await loadPositions();

    // Người dùng có thể đổi phòng ban ngay trong form tạo nhanh; đồng bộ lại
    // trước, nếu không positionOptions lọc theo phòng ban cũ sẽ loại mất nó.
    if (created.department_id && created.department_id !== form.department_id) {
        form.department_id = created.department_id;
    }

    onPositionChange(created.id);
}

/* ---------------- Quản lý trực tiếp (chỉ xem trước, tự động) ---------------- */

// Cây phòng ban (useDepartmentStore, Employees.vue đã nạp sẵn) có kèm `manager`
// của từng nút — dò đúng quy tắc của ReportingLineService ở Backend: Trưởng
// phòng của phòng ban đang chọn; chính người đang sửa là Trưởng phòng hoặc
// phòng chưa có Trưởng phòng -> đi ngược lên phòng ban cha. Chỉ để HIỂN THỊ,
// Backend mới là nơi quyết định (không gửi manager_id lên).
const departmentStore = useDepartmentStore();

const departmentNodesById = computed(() => {
    const map = new Map();
    const walk = (nodes, parentId = null) => {
        for (const node of nodes ?? []) {
            map.set(node.id, {
                ...node,
                parent_id: node.parent_id ?? parentId,
            });
            walk(node.children, node.id);
        }
    };
    walk(departmentStore.tree);
    return map;
});

const predictedManagerName = computed(() => {
    let node = departmentNodesById.value.get(form.department_id);
    const visited = new Set();

    while (node && !visited.has(node.id)) {
        visited.add(node.id);
        if (node.manager && node.manager.id !== props.employee?.id) {
            return `${node.manager.full_name} (${node.manager.code})`;
        }
        node = departmentNodesById.value.get(node.parent_id);
    }

    return form.department_id ? "Chưa có Trưởng phòng" : "Chọn phòng ban trước";
});

/* -------------------- Tỉnh/Xã: Xã tải theo Tỉnh đang chọn ------------------- */

const provinceOptions = ref([]);
const communeOptions = ref([]);
const provincesLoading = ref(false);
const communesLoading = ref(false);

async function loadProvinces() {
    provincesLoading.value = true;
    try {
        const response = await addressService.provinces();
        provinceOptions.value = response.data.map((p) => ({
            title: p.name,
            value: p.code,
        }));
    } finally {
        provincesLoading.value = false;
    }
}

// Danh sách Xã/Phường phụ thuộc Tỉnh — 3300+ xã cả nước nên tải theo từng
// Tỉnh (API `communes?province_code=`) thay vì tải hết 1 lần như Chức vụ
// (chỉ vài chục bản ghi, tải hết mới hợp lý).
async function loadCommunes(provinceCode) {
    if (!provinceCode) {
        communeOptions.value = [];
        return;
    }
    communesLoading.value = true;
    try {
        const response = await addressService.communes(provinceCode);
        communeOptions.value = response.data.map((c) => ({
            title: c.name,
            value: c.code,
        }));
    } finally {
        communesLoading.value = false;
    }
}

// Đổi Tỉnh thì Xã đang chọn (thuộc Tỉnh cũ) không còn hợp lệ — chỉ xóa khi
// người dùng THẬT SỰ đổi (qua @update:model-value), giống hệt lý do
// onDepartmentChange() không dùng watch() chung ở trên.
function onProvinceChange(value) {
    form.province_code = value;
    form.commune_code = null;
    loadCommunes(value);
}

// Chức vụ + Quản lý trực tiếp giờ nạp lại mỗi lần mở modal (xem watch() ở
// trên) — ở đây chỉ còn Tỉnh/Thành phố, vì đó là danh mục gần như không đổi
// trong 1 phiên làm việc, không cần nạp lại mỗi lần mở.
onMounted(() => {
    loadProvinces();
});
</script>
