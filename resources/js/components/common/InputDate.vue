<script>
const pad = (value) => String(value).padStart(2, "0");

// Dựng Date theo giờ địa phương (không dùng new Date("YYYY-MM-DD") vì chuỗi đó
// được hiểu là UTC, lệch múi giờ có thể nhảy sang ngày hôm trước).
export function parseIsoDate(value) {
    if (!value) {
        return null;
    }

    const matched = String(value)
        .slice(0, 10)
        .match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (!matched) {
        return null;
    }

    const [, year, month, day] = matched;
    return new Date(Number(year), Number(month) - 1, Number(day));
}

export function toIsoDate(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return "";
    }

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function todayIso() {
    return toIsoDate(new Date());
}

// Dùng để quy đổi luật "after:X" / "before:X" của backend (so sánh nghiêm ngặt)
// sang min/max của picker (bao gồm hai đầu): after:X  ->  min = X + 1 ngày.
// Trả về undefined khi không có mốc, tức là không đặt giới hạn.
export function shiftIsoDate(value, days) {
    const date = parseIsoDate(value);

    if (!date) {
        return undefined;
    }

    date.setDate(date.getDate() + days);
    return toIsoDate(date);
}
</script>

<script setup>
import { computed } from "vue";
import { VDateInput } from "vuetify/labs/VDateInput";

// Ô nhập ngày dùng chung. Bọc VDateInput (Vuetify labs) thay vì
// <v-text-field type="date"> vì ô ngày gốc của trình duyệt hiển thị theo locale
// của máy người dùng (máy tiếng Anh ra mm/dd/yyyy) và không theo được giao diện
// Vuetify. Ở đây luôn hiện dd/mm/yyyy, còn giá trị trao đổi với form vẫn là
// chuỗi "YYYY-MM-DD" đúng như backend nhận.
const props = defineProps({
    // Chuỗi "YYYY-MM-DD". Nhận luôn cả ISO datetime từ API
    // ("2021-05-09T17:00:00.000000Z") nên view không phải tự cắt phần giờ nữa.
    modelValue: {
        type: String,
        default: "",
    },
    label: {
        type: String,
        default: undefined,
    },
    placeholder: {
        type: String,
        default: "dd/mm/yyyy",
    },
    // Nhận thẳng store.errors.<field> để hiện lỗi 422 ngay dưới ô nhập.
    errorMessages: {
        type: [String, Array],
        default: () => [],
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    clearable: {
        type: Boolean,
        default: true,
    },
    // Giới hạn chọn, cũng dạng "YYYY-MM-DD".
    min: {
        type: String,
        default: undefined,
    },
    max: {
        type: String,
        default: undefined,
    },
});

const emit = defineEmits(["update:modelValue"]);

const dateValue = computed(() => parseIsoDate(props.modelValue));
const minDate = computed(() => parseIsoDate(props.min));
const maxDate = computed(() => parseIsoDate(props.max));

function onUpdate(value) {
    emit("update:modelValue", toIsoDate(value));
}
</script>

<template>
    <v-date-input
        v-bind="$attrs"
        :model-value="dateValue"
        :label="label"
        :placeholder="placeholder"
        :error-messages="errorMessages"
        :disabled="disabled"
        :clearable="clearable"
        :min="minDate"
        :max="maxDate"
        variant="outlined"
        density="comfortable"
        rounded="lg"
        display-format="keyboardDate"
        prepend-icon=""
        prepend-inner-icon="$calendar"
        hide-actions
        @update:model-value="onUpdate"
    />
</template>
