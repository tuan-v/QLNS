<template>
    <v-dialog
        :model-value="modelValue"
        :max-width="maxWidth"
        persistent
        @update:model-value="close"
    >
        <v-card rounded="xl" elevation="12" class="glass-panel overflow-hidden">
            <div class="form-dialog-banner px-6 pt-5 pb-4">
                <div
                    v-if="eyebrow"
                    class="text-caption font-weight-bold"
                    style="letter-spacing: 0.08em; opacity: 0.75"
                >
                    {{ eyebrow }}
                </div>
                <div class="text-h6 font-weight-bold mt-1">{{ title }}</div>
                <div
                    v-if="subtitle"
                    class="text-caption mt-1"
                    style="opacity: 0.8"
                >
                    {{ subtitle }}
                </div>

                <v-btn
                    icon
                    variant="flat"
                    color="white"
                    size="small"
                    class="form-dialog-close"
                    :disabled="loading"
                    @click="close"
                >
                    <v-icon icon="mdi-close" color="grey-darken-3" size="18" />
                </v-btn>
            </div>

            <v-card-text class="px-6 py-5">
                <v-alert
                    v-if="error"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                    icon="mdi-alert-circle-outline"
                >
                    {{ error }}
                </v-alert>

                <!-- Các khối/ô nhập riêng của từng module đặt ở đây -->
                <slot />
            </v-card-text>

            <v-divider />

            <v-card-actions class="px-6 py-4">
                <div
                    v-if="$slots['footer-note']"
                    class="text-caption"
                    style="opacity: 0.65"
                >
                    <slot name="footer-note" />
                </div>
                <v-spacer />
                <!-- Cho phép module tự thay bộ nút nếu cần (vd: thêm nút Xóa) -->
                <slot name="actions">
                    <v-btn
                        variant="text"
                        rounded="lg"
                        :disabled="loading"
                        @click="close"
                    >
                        {{ cancelLabel }}
                    </v-btn>
                    <v-btn
                        :color="submitColor"
                        variant="flat"
                        rounded="lg"
                        class="px-5"
                        :loading="loading"
                        :disabled="submitDisabled"
                        @click="emit('submit')"
                    >
                        {{ submitLabel }}
                    </v-btn>
                </slot>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: "",
    },
    // Dòng chữ nhỏ viết hoa phía trên tiêu đề, ví dụ "HỒ SƠ PHÒNG BAN"
    eyebrow: {
        type: String,
        default: "",
    },
    // Thông báo lỗi chung (khác 422). Lỗi theo từng ô do chính ô đó hiển thị.
    error: {
        type: String,
        default: "",
    },
    loading: {
        type: Boolean,
        default: false,
    },
    submitLabel: {
        type: String,
        default: "Lưu",
    },
    submitColor: {
        type: String,
        default: "primary",
    },
    submitDisabled: {
        type: Boolean,
        default: false,
    },
    cancelLabel: {
        type: String,
        default: "Hủy",
    },
    maxWidth: {
        type: [String, Number],
        default: 620,
    },
});

const emit = defineEmits(["update:modelValue", "submit"]);

function close() {
    emit("update:modelValue", false);
}
</script>

<style scoped>
/* Banner đầu dialog: nền gradient theo màu primary của theme (không hardcode
   mã màu) để tự đổi đúng khi đổi theme, chữ luôn trắng vì nằm trên nền màu. */
.form-dialog-banner {
    position: relative;
    color: #fff;
    background: linear-gradient(
        135deg,
        rgba(var(--v-theme-primary), 0.92),
        rgb(var(--v-theme-background))
    );
}

.form-dialog-close {
    position: absolute;
    top: 14px;
    right: 14px;
}
</style>
