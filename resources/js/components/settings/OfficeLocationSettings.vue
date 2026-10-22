<template>
    <FormSection title="Vị trí công ty">
        <p class="text-body-2 mb-4" style="opacity: 0.75">
            Dùng để tính khoảng cách thật từ nơi nhân viên bấm chấm công tới công ty (hiện ở lịch sử chấm công). Chỉ để
            xem — nhân viên chấm công ở đâu cũng được. Đổi vị trí chỉ áp dụng cho các lượt chấm sau.
        </p>

        <v-progress-linear v-if="loading" indeterminate class="mb-4" />

        <div class="text-body-2 font-weight-medium mb-1">Tọa độ công ty <span class="text-error">*</span></div>
        <v-text-field
            v-model="text"
            variant="outlined"
            density="comfortable"
            rounded="lg"
            :placeholder="PLACEHOLDER"
            hint="Dán tọa độ copy từ Google Maps (dạng độ-phút-giây hoặc số thập phân)."
            persistent-hint
            :disabled="!canManage"
            :error-messages="errorText"
        />

        <div v-if="parsed" class="d-flex flex-wrap align-center ga-3 mt-3 text-body-2">
            <span>
                Vĩ độ <strong>{{ parsed.latitude.toFixed(7) }}</strong
                >, kinh độ
                <strong>{{ parsed.longitude.toFixed(7) }}</strong>
            </span>
            <a :href="mapsUrl" target="_blank" rel="noopener">Xem trên Google Maps</a>
        </div>
        <div v-if="current?.updated_by" class="text-caption text-medium-emphasis mt-1">
            Cập nhật lần cuối bởi {{ current.updated_by }} lúc {{ formatDateTime(current.updated_at) }}
        </div>

        <div class="d-flex flex-wrap justify-end ga-2 mt-5">
            <v-btn
                variant="text"
                prepend-icon="mdi-crosshairs-gps"
                :loading="locating"
                :disabled="!canManage"
                @click="useMyPosition"
            >
                Dùng vị trí hiện tại của tôi
            </v-btn>
            <v-btn
                color="primary"
                variant="flat"
                :loading="saving"
                :disabled="!canManage || !parsed || !changed"
                @click="save"
            >
                Lưu vị trí
            </v-btn>
        </div>
    </FormSection>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import FormSection from "../common/FormSection.vue";
import settingsService from "../../services/settingsService";
import { useToastStore } from "../../stores/useToastStore";

// Mục "Vị trí công ty" của trang Cài đặt hệ thống (2026-10-22) — xem App\Services\Attendance\OfficeLocation.
defineProps({
    canManage: { type: Boolean, default: false },
});

const toast = useToastStore();
const loading = ref(true);
const saving = ref(false);
const locating = ref(false);
const current = ref(null);
const text = ref("");
const serverError = ref("");
const PLACEHOLDER = `Vd: 21°02'28.5"N 105°43'55.9"E hoặc 21.04125, 105.7321944`;
watch(text, () => (serverError.value = ""));

// Nhận 2 dạng: độ-phút-giây của Google Maps (21°02'28.5"N 105°43'55.9"E) hoặc "21.04125, 105.7321944".
function parseCoordinates(value) {
    const input = (value ?? "").trim();
    if (!input) return null;

    const dms = [
        ...input.matchAll(
            /(\d+(?:\.\d+)?)\s*°\s*(?:(\d+(?:\.\d+)?)\s*['′])?\s*(?:(\d+(?:\.\d+)?)\s*(?:["″]|''))?\s*([NSEW])/gi,
        ),
    ];
    if (dms.length === 2) {
        const parts = dms.map(([, deg, min, sec, dir]) => {
            const abs = Number(deg) + Number(min ?? 0) / 60 + Number(sec ?? 0) / 3600;
            return { value: /[SW]/i.test(dir) ? -abs : abs, isLat: /[NS]/i.test(dir) };
        });
        const lat = parts.find((p) => p.isLat);
        const lng = parts.find((p) => !p.isLat);
        return lat && lng ? valid(lat.value, lng.value) : null;
    }

    const numbers = input.match(/-?\d+(?:\.\d+)?/g);
    return numbers?.length === 2 ? valid(Number(numbers[0]), Number(numbers[1])) : null;
}
const valid = (latitude, longitude) =>
    Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180 ? { latitude, longitude } : null;

const parsed = computed(() => parseCoordinates(text.value));
const errorText = computed(
    () => serverError.value || (text.value.trim() && !parsed.value ? "Không đọc được tọa độ." : ""),
);
const changed = computed(
    () =>
        !current.value ||
        Math.abs(current.value.latitude - parsed.value?.latitude) > 1e-7 ||
        Math.abs(current.value.longitude - parsed.value?.longitude) > 1e-7,
);
const mapsUrl = computed(() => `https://www.google.com/maps?q=${parsed.value.latitude},${parsed.value.longitude}`);

const formatDateTime = (value) => (value ? new Date(value).toLocaleString("vi-VN") : "");

function fill(location) {
    current.value = location;
    text.value = location ? `${location.latitude}, ${location.longitude}` : "";
}

async function load() {
    loading.value = true;
    try {
        fill((await settingsService.officeLocation()).data.data);
    } catch (e) {
        serverError.value = e.response?.data?.message ?? "Không tải được vị trí công ty.";
    } finally {
        loading.value = false;
    }
}

function useMyPosition() {
    if (!navigator.geolocation) {
        toast.warning("Trình duyệt không hỗ trợ định vị.");
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            text.value = `${coords.latitude.toFixed(7)}, ${coords.longitude.toFixed(7)}`;
            locating.value = false;
            toast.info(`Đã lấy vị trí hiện tại (thiết bị báo độ chính xác ±${Math.round(coords.accuracy)}m).`);
        },
        () => {
            locating.value = false;
            toast.warning("Không lấy được vị trí — kiểm tra quyền truy cập vị trí của trình duyệt.");
        },
        { enableHighAccuracy: true, timeout: 10000 },
    );
}

async function save() {
    saving.value = true;
    serverError.value = "";
    try {
        const { data } = await settingsService.updateOfficeLocation(parsed.value);
        fill(data.data);
        toast.success(data.message);
    } catch (e) {
        const errors = e.response?.data?.errors;
        serverError.value = errors ? Object.values(errors)[0][0] : (e.response?.data?.message ?? "Không lưu được.");
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
