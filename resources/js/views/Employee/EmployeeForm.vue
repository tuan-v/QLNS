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
                    <div class="text-body-2 font-weight-medium mb-1">
                        Phòng ban <span class="text-error">*</span>
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
                    <div class="text-body-2 font-weight-medium mb-1">
                        Chức vụ
                    </div>
                    <SearchSelect
                        v-model="form.position_id"
                        :items="positionOptions"
                        clearable
                        :error-messages="store.errors.position_id"
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
        </v-form>

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
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";
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
            // Nạp lại danh sách Xã/Phường đúng theo Tỉnh đã có sẵn (modal Sửa) —
            // KHÔNG gọi qua onProvinceChange() vì hàm đó xóa luôn commune_code,
            // ở đây form.commune_code vừa được fillForm() gán đúng giá trị cũ.
            loadCommunes(form.province_code);
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
        } else {
            await store.create(payload);
        }
        toast.success(
            isEdit.value ? "Đã cập nhật nhân viên." : "Đã thêm nhân viên mới.",
        );
        emit("saved");
        close();
    } catch {
        // Lỗi đã được store xử lý (422 -> store.errors, còn lại -> store.loadError),
        // giữ modal mở để người dùng sửa lại dữ liệu.
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

onMounted(() => {
    loadPositions();
    loadManagerOptions();
    loadProvinces();
});
</script>
