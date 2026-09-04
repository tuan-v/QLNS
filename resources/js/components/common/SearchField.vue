<script setup>
import { onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: 'Tìm kiếm...',
    },
    debounce: {
        type: Number,
        default: 300,
    },
});

const emit = defineEmits(['update:modelValue']);

const localValue = ref(props.modelValue);
let debounceTimer = null;

watch(() => props.modelValue, (value) => {
    if (value !== localValue.value) {
        localValue.value = value;
    }
});

watch(localValue, (value) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        // nút xoá của v-text-field trả về null, chuẩn hoá về chuỗi rỗng
        emit('update:modelValue', value ?? '');
    }, props.debounce);
});

onUnmounted(() => {
    clearTimeout(debounceTimer);
});
</script>

<template>
  <v-text-field
    v-model="localValue"
    :placeholder="placeholder"
    prepend-inner-icon="mdi-magnify"
    variant="outlined"
    density="compact"
    clearable
    hide-details
    single-line
    style="max-width: 320px;"
  />
</template>
