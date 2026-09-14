<template>
    <v-sheet class="border rounded-lg pa-5 glass-panel" color="transparent">
        <div class="d-flex align-center justify-space-between flex-wrap ga-4 mb-5">
            <div class="d-flex align-center ga-4">
                <v-avatar size="72" color="surface-variant">
                    <v-img v-if="employee.avatar_url" :src="employee.avatar_url" />
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

            <!-- Tài khoản đăng nhập: hiện thông tin nếu đã có (email + Role),
                 hoặc nút tạo nếu chưa có — xem EmployeeAccountController. -->
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
    </v-sheet>
</template>

<script setup>
// Tab "Sơ yếu lý lịch" của EmployeeDetail.vue — tách riêng theo yêu cầu dễ
// bảo trì (file gốc trước đây gộp cả 7 tab vào 1 file 1865 dòng). Khác các
// tab kia (Hợp đồng/Tài liệu/Luân chuyển/Ca làm việc): KHÔNG tự tải dữ liệu
// riêng — nhận thẳng `employee` đã tải sẵn từ component cha, vì đây chính là
// dữ liệu hiển thị ở tab đầu tiên (không có API "profile" riêng nào khác).
import { computed, ref } from "vue";
import employeeService from "../../services/employeeService";
import roleService from "../../services/roleService";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    employee: {
        type: Object,
        required: true,
    },
    canUpdate: {
        type: Boolean,
        default: false,
    },
});

// Báo cho EmployeeDetail.vue tải lại `employee` sau khi tạo tài khoản xong —
// employee.user cần cập nhật để ẩn nút "Tạo tài khoản đăng nhập" đi.
const emit = defineEmits(["account-created"]);

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

const profileFields = computed(() => {
    const e = props.employee;
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
        props.employee?.position?.suggested_roles?.map((r) => r.id) ?? [];
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
        await employeeService.createAccount(props.employee.id, {
            role_ids: accountRoleIds.value,
        });
        toast.success(
            "Đã tạo tài khoản đăng nhập, email đặt mật khẩu đã được gửi.",
        );
        closeAccountDialog();
        emit("account-created");
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
</script>
