<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="ĐIỂM CHẤM CÔNG"
        :title="isEdit ? 'Sửa điểm chấm công' : 'Thêm điểm chấm công'"
        :subtitle="
            isEdit
                ? 'Cập nhật thông tin điểm chấm công.'
                : 'Tạo điểm chấm công mới (Wifi/GPS/QR).'
        "
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin cơ bản">
            <v-row dense>
                <v-col cols="12" sm="7">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tên điểm chấm công <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.name"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="VD: Văn phòng chính"
                        :error-messages="errors.name"
                    />
                </v-col>

                <v-col cols="12" sm="5">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Mã điểm
                    </div>
                    <v-text-field
                        :model-value="isEdit ? location.code : 'Tự động sau khi lưu'"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        readonly
                        prepend-inner-icon="mdi-auto-fix"
                        :class="{ 'text-medium-emphasis': !isEdit }"
                    />
                </v-col>
            </v-row>

            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">
                    Phương thức chấm công <span class="text-error">*</span>
                </div>
                <v-select
                    v-model="form.method"
                    :items="METHOD_OPTIONS"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.method"
                />
            </div>

            <!-- Wifi: xác định bằng tên Wifi (SSID), có thể kèm dải IP mạng nội bộ -->
            <template v-if="form.method === 'wifi'">
                <div class="mb-3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tên Wifi (SSID) <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.wifi_ssid"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="VD: QLNS-Office"
                        :error-messages="errors.wifi_ssid"
                    />
                </div>
                <div class="mb-3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Dải IP cho phép (tùy chọn)
                    </div>
                    <v-text-field
                        v-model="form.allowed_ip_cidr"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="VD: 192.168.1.0/24"
                        :error-messages="errors.allowed_ip_cidr"
                    />
                </div>
            </template>

            <!-- GPS: xác định bằng tọa độ + bán kính cho phép check-in quanh đó -->
            <template v-else-if="form.method === 'gps'">
                <v-row dense>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Vĩ độ (Latitude) <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model.number="form.latitude"
                            type="number"
                            step="0.0000001"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            placeholder="VD: 10.7769000"
                            :error-messages="errors.latitude"
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Kinh độ (Longitude) <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model.number="form.longitude"
                            type="number"
                            step="0.0000001"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            placeholder="VD: 106.7009000"
                            :error-messages="errors.longitude"
                        />
                    </v-col>
                </v-row>
                <div class="mb-3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Bán kính cho phép (mét) <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model.number="form.radius_meters"
                        type="number"
                        min="1"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="VD: 100"
                        :error-messages="errors.radius_meters"
                    />
                </div>
            </template>

            <!-- QR: bí mật để dựng mã QR do hệ thống tự sinh, không nhập tay -->
            <template v-else-if="form.method === 'qr'">
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    <template v-if="isEdit && location?.qr_secret">
                        Mã bí mật QR: <strong>{{ location.qr_secret }}</strong>
                        — dùng để dựng mã QR dán tại điểm chấm công này.
                    </template>
                    <template v-else>
                        Mã bí mật QR sẽ được hệ thống tự sinh sau khi lưu, dùng để
                        dựng mã QR dán tại điểm chấm công này.
                    </template>
                </v-alert>
            </template>

            <div class="d-flex align-center justify-space-between">
                <div>
                    <div class="text-body-2 font-weight-medium">
                        Trạng thái hoạt động
                    </div>
                    <div class="text-caption" style="opacity: 0.65">
                        Điểm ngừng hoạt động vẫn được lưu nhưng không dùng để
                        chấm công mới.
                    </div>
                </div>
                <v-switch
                    v-model="form.is_active"
                    color="success"
                    density="compact"
                    hide-details
                    inset
                    class="flex-grow-0 ms-4"
                />
            </div>
        </FormSection>

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import attendanceLocationService from "../../services/attendanceLocationService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    // null = thêm mới, object = sửa điểm chấm công đang chọn
    location: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();

const isEdit = computed(() => props.location !== null);

const METHOD_OPTIONS = [
    { title: "Wifi", value: "wifi" },
    { title: "GPS", value: "gps" },
    { title: "Mã QR", value: "qr" },
];

// Không dùng Pinia store (chỉ 1 trang dùng, giống Position/WorkShift).
const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const form = reactive({
    name: "",
    method: "wifi",
    wifi_ssid: "",
    allowed_ip_cidr: "",
    latitude: null,
    longitude: null,
    radius_meters: null,
    is_active: true,
});

function fillForm() {
    form.name = props.location?.name ?? "";
    form.method = props.location?.method ?? "wifi";
    form.wifi_ssid = props.location?.wifi_ssid ?? "";
    form.allowed_ip_cidr = props.location?.allowed_ip_cidr ?? "";
    form.latitude = props.location?.latitude ?? null;
    form.longitude = props.location?.longitude ?? null;
    form.radius_meters = props.location?.radius_meters ?? null;
    // DB trả về 1/0, ép về boolean cho v-switch
    form.is_active = Boolean(props.location?.is_active ?? true);
}

// Mỗi lần mở modal: nạp lại dữ liệu và xóa lỗi của lần mở trước
watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            errors.value = {};
            loadError.value = "";
            fillForm();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    try {
        const response = isEdit.value
            ? await attendanceLocationService.update(props.location.id, { ...form })
            : await attendanceLocationService.create({ ...form });
        toast.success(
            isEdit.value
                ? "Đã cập nhật điểm chấm công."
                : "Đã thêm điểm chấm công mới.",
        );
        emit("saved", response.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value =
                e.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
        // Giữ modal mở để người dùng sửa lại dữ liệu.
    } finally {
        loading.value = false;
    }
}
</script>
