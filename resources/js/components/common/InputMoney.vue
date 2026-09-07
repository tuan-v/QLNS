<script setup>
import { nextTick, ref, watch } from "vue";

// Ô nhập tiền dùng chung: gõ 1500000 hiện thành "1.500.000" nhưng giá trị đẩy ra
// ngoài vẫn là số thuần (1500000) để gửi thẳng lên API, không phải bóc dấu chấm
// ở từng form. Dùng cho lương, phụ cấp, khấu trừ ở module Tính lương.
const props = defineProps({
    modelValue: {
        type: [Number, String],
        default: null,
    },
    label: {
        type: String,
        default: undefined,
    },
    placeholder: {
        type: String,
        default: "0",
    },
    suffix: {
        type: String,
        default: "₫",
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
        default: false,
    },
});

const emit = defineEmits(["update:modelValue"]);

const formatter = new Intl.NumberFormat("vi-VN");

function toNumber(value) {
    const digits = String(value ?? "").replace(/\D/g, "");
    return digits === "" ? null : Number(digits);
}

function toDisplay(value) {
    const numeric = typeof value === "number" ? value : toNumber(value);
    return numeric === null ? "" : formatter.format(numeric);
}

const display = ref(toDisplay(props.modelValue));

// Chỉ vẽ lại khi giá trị từ ngoài thực sự khác — nếu không, mỗi lần gõ sẽ bị
// component tự ghi đè lại chính ô đang nhập.
watch(
    () => props.modelValue,
    (value) => {
        if (toNumber(display.value) !== toNumber(value)) {
            display.value = toDisplay(value);
        }
    },
);

function onInput(event) {
    const input = event.target;
    // Đếm số CHỮ SỐ đứng trước con trỏ, không dùng vị trí ký tự: sau khi chèn
    // thêm dấu chấm phân cách, vị trí ký tự lệch đi còn số chữ số thì không.
    const digitsBeforeCaret = input.value
        .slice(0, input.selectionStart ?? 0)
        .replace(/\D/g, "").length;

    const numeric = toNumber(input.value);
    const formatted = toDisplay(numeric);

    display.value = formatted;
    emit("update:modelValue", numeric);

    nextTick(() => {
        let position = 0;
        let seen = 0;

        while (position < formatted.length && seen < digitsBeforeCaret) {
            if (/\d/.test(formatted[position])) {
                seen += 1;
            }
            position += 1;
        }

        input.setSelectionRange(position, position);
    });
}
</script>

<template>
    <v-text-field
        v-bind="$attrs"
        :model-value="display"
        :label="label"
        :placeholder="placeholder"
        :suffix="suffix"
        :error-messages="errorMessages"
        :disabled="disabled"
        :clearable="clearable"
        variant="outlined"
        density="comfortable"
        rounded="lg"
        inputmode="numeric"
        @input="onInput"
        @click:clear="emit('update:modelValue', null)"
    />
</template>
