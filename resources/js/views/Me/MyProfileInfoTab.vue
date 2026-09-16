<template>
    <v-sheet class="border rounded-lg pa-5 glass-panel" color="transparent">
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
    </v-sheet>
</template>

<script setup>
// Tab "Thông tin cá nhân" của MyProfile.vue — tách riêng theo yêu cầu dễ
// bảo trì (giống EmployeeDetail.vue). Nhận `employee` từ component cha (đã
// tải sẵn ở tab đầu tiên), chỉ tự lo dialog "Sửa thông tin liên hệ" (5 field
// duy nhất được tự sửa — xem UpdateMyProfileRequest ở backend).
import { computed, ref } from "vue";
import employeeService from "../../services/employeeService";
import addressService from "../../services/addressService";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    employee: {
        type: Object,
        required: true,
    },
});

// Cha cần cập nhật lại `employee` sau khi sửa liên hệ xong — cả thẻ hồ sơ
// đầu trang lẫn StatCards đều đọc chung 1 nguồn dữ liệu này.
const emit = defineEmits(["updated"]);

const toast = useToastStore();

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
        {
            label: "Địa chỉ",
            value:
                [e.address_detail, e.commune?.name, e.province?.name]
                    .filter(Boolean)
                    .join(", ") || null,
        },
        { label: "Phòng ban", value: e.department?.name },
        { label: "Chức vụ", value: e.position?.name },
        { label: "Quản lý trực tiếp", value: e.manager?.full_name },
        { label: "Ngày vào làm", value: formatDate(e.hire_date) },
    ];
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
        provinceOptions.value = response.data.map((p) => ({
            title: p.name,
            value: p.code,
        }));
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
        communeOptions.value = response.data.map((c) => ({
            title: c.name,
            value: c.code,
        }));
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
    const e = props.employee;
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
        const response = await employeeService.updateMe({
            ...contactForm.value,
        });
        emit("updated", response.data.data);
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
            contactGeneralError.value =
                data?.message ?? "Không thể kết nối máy chủ.";
        }
    } finally {
        contactSubmitting.value = false;
    }
}
</script>
