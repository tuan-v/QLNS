<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-calendar-account-outline"
        size="md"
        :title="
            isBulk
                ? `Hẹn phỏng vấn ${bulkCandidates.length} ứng viên`
                : isReschedule
                  ? 'Dời lịch phỏng vấn'
                  : 'Hẹn phỏng vấn'
        "
        :subtitle="
            isBulk
                ? 'Mỗi ứng viên 1 khung giờ liên tiếp, cùng địa điểm — hệ thống tự gửi email mời cho từng người.'
                : `Ứng viên: ${candidate?.full_name ?? ''} — hệ thống tự gửi email ${isReschedule ? 'báo đổi lịch' : 'mời phỏng vấn'} cho ứng viên.`
        "
        :error="loadError"
        :loading="loading"
        :submit-label="
            isBulk
                ? `Hẹn ${bulkCandidates.length} người & gửi email`
                : isReschedule
                  ? 'Dời lịch & gửi email'
                  : 'Hẹn & gửi email'
        "
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Lịch phỏng vấn" :columns="2">
            <FormField :label="isBulk ? 'Bắt đầu từ' : 'Thời gian'" required>
                <v-text-field
                    v-model="form.scheduled_at"
                    type="datetime-local"
                    density="comfortable"
                    :min="minDateTime"
                    :rules="[notEmpty('Thời gian')]"
                    :error-messages="errors.scheduled_at || errors.start_at"
                />
            </FormField>
            <FormField label="Hình thức" required>
                <v-select
                    v-model="form.format"
                    :items="FORMAT_OPTIONS"
                    density="comfortable"
                    :error-messages="errors.format"
                />
            </FormField>

            <template v-if="isBulk">
                <FormField label="Mỗi lượt" required>
                    <v-select
                        v-model="form.slot_minutes"
                        :items="SLOT_OPTIONS"
                        density="comfortable"
                        :error-messages="errors.slot_minutes"
                    />
                </FormField>
                <FormField label="Lịch dự kiến">
                    <div
                        class="text-body-2"
                        style="max-height: 120px; overflow-y: auto"
                    >
                        <div v-for="slot in slotPreview" :key="slot.id">
                            <strong>{{ slot.time }}</strong> — {{ slot.name }}
                        </div>
                        <div v-if="!slotPreview.length" style="opacity: 0.6">
                            Chọn giờ bắt đầu để xem lịch.
                        </div>
                    </div>
                </FormField>
            </template>

            <template v-if="form.format === 'onsite'">
                <FormField label="Tỉnh / Thành phố" required>
                    <SearchSelect
                        :model-value="form.province_code"
                        :items="provinceOptions"
                        :loading="provincesLoading"
                        placeholder="Chọn Tỉnh/Thành phố"
                        :rules="[notEmpty('Tỉnh/Thành phố')]"
                        :error-messages="errors.province_code"
                        @update:model-value="onProvinceChange"
                    />
                </FormField>
                <FormField label="Xã / Phường" required>
                    <SearchSelect
                        v-model="form.commune_code"
                        :items="communeOptions"
                        :loading="communesLoading"
                        :disabled="!form.province_code"
                        :placeholder="
                            form.province_code
                                ? 'Chọn Xã/Phường'
                                : 'Chọn Tỉnh/Thành phố trước'
                        "
                        :rules="[notEmpty('Xã/Phường')]"
                        :error-messages="errors.commune_code"
                    />
                </FormField>
                <FormField
                    label="Địa chỉ chi tiết"
                    required
                    span="full"
                    hint="Số nhà, đường, tòa nhà, phòng họp..."
                >
                    <v-text-field
                        v-model="form.address_detail"
                        density="comfortable"
                        placeholder="Phòng họp tầng 3, 123 Đường ABC"
                        :rules="[
                            notEmpty('Địa chỉ chi tiết'),
                            maxLength(255, 'Địa chỉ chi tiết'),
                        ]"
                        :error-messages="errors.address_detail"
                    />
                </FormField>
            </template>

            <FormField v-else label="Link phỏng vấn" required span="full">
                <v-text-field
                    v-model="form.location"
                    density="comfortable"
                    placeholder="https://meet.google.com/..."
                    :rules="[
                        notEmpty('Link'),
                        (v) =>
                            !v ||
                            /^https?:\/\//i.test(v) ||
                            'Link phải bắt đầu bằng http:// hoặc https://',
                    ]"
                    :error-messages="errors.location"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import addressService from "../../services/addressService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";
import {
    maxLength,
    notEmpty,
    useClearErrorsOnEdit,
} from "../../composables/validationRules";

const FORMAT_OPTIONS = [
    { title: "Trực tiếp", value: "onsite" },
    { title: "Trực tuyến", value: "online" },
];

const SLOT_OPTIONS = [15, 20, 30, 45, 60, 90].map((m) => ({
    title: `${m} phút`,
    value: m,
}));

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    candidate: { type: Object, default: null },
    // Chế độ hàng loạt: danh sách ứng viên (đã duyệt) — hẹn theo khung giờ liên tiếp.
    bulkCandidates: { type: Array, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const form = reactive({
    scheduled_at: "",
    format: "onsite",
    location: "",
    province_code: null,
    commune_code: null,
    address_detail: "",
    slot_minutes: 30,
});

const isBulk = computed(
    () =>
        Array.isArray(props.bulkCandidates) && props.bulkCandidates.length > 0,
);
const currentInterview = computed(() =>
    isBulk.value
        ? null
        : (props.candidate?.interviews?.find((i) => i.status === "scheduled") ??
          null),
);

// Xem trước giờ của từng người (giờ thật do backend tính — ứng viên lỗi không chiếm khung).
const slotPreview = computed(() => {
    if (!isBulk.value || !form.scheduled_at) return [];
    const start = new Date(form.scheduled_at);
    return props.bulkCandidates.map((c, i) => ({
        id: c.id,
        name: c.full_name,
        time: new Date(
            start.getTime() + i * form.slot_minutes * 60000,
        ).toLocaleString("vi-VN", {
            hour: "2-digit",
            minute: "2-digit",
            day: "2-digit",
            month: "2-digit",
        }),
    }));
});
const isReschedule = computed(() => currentInterview.value !== null);

// "YYYY-MM-DDTHH:mm" theo giờ máy, cho thuộc tính min của datetime-local.
function toLocalInput(date) {
    const pad = (n) => String(n).padStart(2, "0");
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
const minDateTime = computed(() => toLocalInput(new Date()));

/* ------------- Địa chỉ Tỉnh -> Xã (cùng danh mục với hồ sơ nhân viên) ------------- */
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
    } catch {
        provinceOptions.value = [];
    } finally {
        provincesLoading.value = false;
    }
}

async function loadCommunes(provinceCode) {
    if (!provinceCode) {
        communeOptions.value = [];
        return;
    }
    communesLoading.value = true;
    try {
        const response = await addressService.communes(provinceCode);
        // Người dùng đã đổi tỉnh khác trong lúc chờ -> bỏ kết quả cũ.
        if (form.province_code === provinceCode) {
            communeOptions.value = response.data.map((c) => ({
                title: c.name,
                value: c.code,
            }));
        }
    } catch {
        communeOptions.value = [];
    } finally {
        communesLoading.value = false;
    }
}

// Đổi tỉnh thì xã đang chọn (thuộc tỉnh cũ) không còn hợp lệ.
function onProvinceChange(value) {
    form.province_code = value;
    form.commune_code = null;
    loadCommunes(value);
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            return;
        }
        // Dời lịch: điền lại đúng lịch cũ (kể cả tỉnh/xã đã chọn).
        const i = currentInterview.value;
        Object.assign(form, {
            scheduled_at: i ? toLocalInput(new Date(i.scheduled_at)) : "",
            format: i?.format ?? "onsite",
            location: i?.format === "online" ? (i.location ?? "") : "",
            province_code: i?.province_code ?? null,
            commune_code: i?.commune_code ?? null,
            address_detail: i?.address_detail ?? "",
            slot_minutes: 30,
        });
        errors.value = {};
        loadError.value = "";
        loadProvinces();
        loadCommunes(form.province_code);
    },
);

useClearErrorsOnEdit(form, () => errors.value);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    const onsite = form.format === "onsite";
    // Chỉ gửi phần ứng với hình thức đang chọn.
    const place = {
        format: form.format,
        location: onsite ? null : form.location,
        province_code: onsite ? form.province_code : null,
        commune_code: onsite ? form.commune_code : null,
        address_detail: onsite ? form.address_detail : null,
    };
    try {
        if (isBulk.value) {
            const { data } = await recruitmentService.bulkScheduleInterviews({
                ...place,
                candidate_ids: props.bulkCandidates.map((c) => c.id),
                start_at: form.scheduled_at.replace("T", " "),
                slot_minutes: form.slot_minutes,
            });
            if (data.done) {
                toast.success(`Đã hẹn ${data.done} ứng viên và gửi email mời.`);
                emit("saved");
            }
            if (data.failed.length) {
                // Giữ dialog mở để người dùng thấy ai chưa hẹn được và vì sao.
                loadError.value =
                    "Chưa hẹn được: " +
                    data.failed
                        .map((f) => `${f.full_name} (${f.message})`)
                        .join("; ");
                return;
            }
        } else {
            await recruitmentService.scheduleInterview(props.candidate.id, {
                ...place,
                scheduled_at: form.scheduled_at.replace("T", " "),
            });
            toast.success(
                isReschedule.value
                    ? "Đã dời lịch và gửi email cho ứng viên."
                    : "Đã hẹn phỏng vấn và gửi email mời.",
            );
            emit("saved");
        }
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value =
                e.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
