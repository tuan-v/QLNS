<template>
    <FormDialog
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
        <v-form ref="formRef">
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
                        prepend-inner-icon="mdi-auto-fix"
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
                        :error-messages="store.errors.company_email"
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
                        :error-messages="store.errors.phone"
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
                        :error-messages="store.errors.personal_email"
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
                        :error-messages="store.errors.cccd"
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
                        :error-messages="store.errors.personal_tax_code"
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

                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Quản lý trực tiếp
                    </div>
                    <SearchSelect
                        v-model="form.manager_id"
                        :items="managerOptions"
                        clearable
                        :error-messages="store.errors.manager_id"
                    />
                </v-col>

                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Trạng thái làm việc
                    </div>
                    <v-select
                        v-model="form.employment_status"
                        :items="statusOptions"
                        placeholder="Chưa chọn"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        clearable
                        persistent-placeholder
                        :error-messages="store.errors.employment_status"
                    />
                </v-col>

                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Ngày kết thúc thử việc
                    </div>
                    <InputDate
                        v-model="form.probation_end_date"
                        :min="minAfterHireDate"
                        :error-messages="store.errors.probation_end_date"
                    />
                </v-col>

                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Ngày chấm dứt hợp đồng
                    </div>
                    <InputDate
                        v-model="form.termination_date"
                        :min="minAfterHireDate"
                        :error-messages="store.errors.termination_date"
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
                    Tự điền theo Chức vụ đang chọn, sửa được. Mật khẩu gửi qua
                    email cho nhân viên tự đặt, không hiển thị ở đây.
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
import employeeService from "../../services/employeeService";
import positionService from "../../services/positionService";
import addressService from "../../services/addressService";
import roleService from "../../services/roleService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";
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

const isEdit = computed(() => props.employee !== null);

const genderOptions = [
    { title: "Nam", value: "male" },
    { title: "Nữ", value: "female" },
    { title: "Khác", value: "other" },
];

const statusOptions = [
    { title: "Thử việc", value: "probation" },
    { title: "Đang làm việc", value: "active" },
    { title: "Đã nghỉ việc", value: "resigned" },
    { title: "Đã chấm dứt HĐ", value: "terminated" },
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
    manager_id: null,
    employment_status: null,
    probation_end_date: "",
    termination_date: "",
});

const formRef = ref(null);

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
const notEmpty = (label) => (value) =>
    (value !== null && value !== undefined && String(value).trim() !== "") ||
    `${label} không được để trống`;

const maxLength = (limit, label) => (value) =>
    !value ||
    String(value).length <= limit ||
    `${label} không được vượt quá ${limit} ký tự`;

const isEmail = (label) => (value) =>
    !value || /^\S+@\S+\.\S+$/.test(String(value)) || `${label} không đúng định dạng`;

const rules = {
    fullName: [notEmpty("Tên nhân viên"), maxLength(255, "Tên nhân viên")],
    companyEmail: [notEmpty("Email công ty"), isEmail("Email công ty")],
    hireDate: [notEmpty("Ngày vào làm")],
    dateOfBirth: [notEmpty("Ngày sinh")],
    gender: [notEmpty("Giới tính")],
    phone: [notEmpty("Số điện thoại"), maxLength(10, "Số điện thoại")],
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
    addressDetail: [notEmpty("Địa chỉ chi tiết"), maxLength(255, "Địa chỉ chi tiết")],
    provinceCode: [notEmpty("Tỉnh/Thành phố")],
    communeCode: [notEmpty("Xã/Phường")],
    departmentId: [notEmpty("Phòng ban")],
};

// Giới hạn của picker phản chiếu đúng rule trong StoreEmployeeRequest /
// UpdateEmployeeRequest, để người dùng không chọn được ngày mà backend chắc
// chắn trả 422. Backend vẫn là nơi kiểm tra cuối cùng, đây chỉ là chặn sớm.
//
// date_of_birth: 'before:today' -> ngày muộn nhất chọn được là hôm qua.
const maxBirthDate = computed(() => shiftIsoDate(todayIso(), -1));
// probation_end_date / termination_date: 'after:hire_date' -> sớm nhất là
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
    form.manager_id = e?.manager?.id ?? null;
    form.employment_status = e?.employment_status ?? null;
    form.probation_end_date = toDateInput(e?.probation_end_date);
    form.termination_date = toDateInput(e?.termination_date);
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
            fillForm();
            // Xóa lỗi đỏ còn sót của lần mở trước, tránh form vừa mở đã báo lỗi.
            formRef.value?.resetValidation();
            // Tài khoản đăng nhập chỉ áp dụng lúc Thêm mới — reset lại mỗi lần
            // mở modal, không giữ trạng thái tick của lần thêm trước.
            createAccount.value = false;
            accountRoleIds.value = [];
            accountError.value = "";
            loadRoles();
            // Nạp lại danh sách Xã/Phường đúng theo Tỉnh đã có sẵn (modal Sửa) —
            // KHÔNG gọi qua onProvinceChange() vì hàm đó xóa luôn commune_code,
            // ở đây form.commune_code vừa được fillForm() gán đúng giá trị cũ.
            loadCommunes(form.province_code);
            // Nạp lại Chức vụ + Quản lý trực tiếp mỗi lần mở modal (không chỉ
            // onMounted) — nếu không, nhân viên vừa thêm xong trong modal này sẽ
            // KHÔNG xuất hiện trong danh sách "Quản lý trực tiếp" khi mở modal
            // Thêm/Sửa tiếp theo trong cùng phiên, vì allManagers chỉ nạp 1 lần
            // lúc EmployeeForm.vue mount (là component thường trực, không phải
            // mount/unmount theo mỗi lần mở).
            loadPositions();
            loadManagerOptions();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    // Chặn ngay ở client nếu còn ô chưa hợp lệ — v-form tự cuộn tới ô lỗi đầu tiên.
    const { valid } = await formRef.value.validate();

    if (!valid) {
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
        toast.success("Đã tạo tài khoản đăng nhập, email đặt mật khẩu đã được gửi.");
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

/* --------------------------- Quản lý trực tiếp --------------------------- */

const allManagers = ref([]);

// Cố tình KHÔNG dùng useEmployeeStore() ở đây — store đó đang giữ danh sách
// phân trang thật của bảng Employees.vue (cùng trang, cùng lúc mở modal này);
// gọi fetchList({per_page:1000}) trên cùng store sẽ ghi đè mất dữ liệu bảng
// đang hiển thị. Gọi thẳng employeeService vào 1 ref cục bộ để tránh đụng độ.
async function loadManagerOptions() {
    const response = await employeeService.list({ per_page: 1000 });
    allManagers.value = response.data.data;
}

// Loại chính nhân viên đang sửa khỏi danh sách chọn — tính lại mỗi lần
// props.employee đổi (modal chỉ tạo 1 lần, mở lại nhiều lần cho nhiều nhân
// viên khác nhau), không gộp vào loadManagerOptions() vì hàm đó chỉ chạy 1
// lần lúc mount.
const managerOptions = computed(() =>
    allManagers.value
        .filter((e) => !isEdit.value || e.id !== props.employee?.id)
        .map((e) => ({ title: `${e.full_name} (${e.code})`, value: e.id })),
);

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
