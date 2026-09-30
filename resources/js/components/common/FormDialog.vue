<template>
    <!-- Khung dialog form DÙNG CHUNG cho toàn app (design system QLNS):
         header (icon + tiêu đề + mô tả + nút đóng) · body cuộn được, có
         class qlns-form để chuẩn hoá khoảng cách ô nhập · footer (ghi chú +
         Hủy/Lưu). Nội dung đặt trong <FormSection> + <FormField>. -->
    <v-dialog
        :model-value="modelValue"
        :max-width="dialogWidth"
        scrollable
        persistent
        transition="fade-transition"
        @update:model-value="close"
    >
        <v-card rounded="xl" class="form-dialog-card">
            <header class="form-dialog-head">
                <div v-if="icon" class="form-dialog-icon">
                    <v-icon :icon="icon" size="20" />
                </div>
                <div class="min-width-0 flex-grow-1">
                    <h2 class="form-dialog-title">{{ title }}</h2>
                    <p v-if="subtitle" class="form-dialog-subtitle">{{ subtitle }}</p>
                </div>
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    size="small"
                    class="form-dialog-close"
                    :disabled="loading"
                    aria-label="Đóng"
                    @click="close"
                />
            </header>

            <v-card-text class="form-dialog-body qlns-form">
                <!-- Lỗi chung (khác 422) — lỗi từng ô do chính ô đó hiển thị -->
                <v-expand-transition>
                    <v-alert
                        v-if="error"
                        type="error"
                        variant="tonal"
                        density="compact"
                        class="mb-5"
                        icon="mdi-alert-circle-outline"
                    >
                        {{ error }}
                    </v-alert>
                </v-expand-transition>

                <!-- Kiểm tra ô nhập kiểu "báo khi rời ô": không đỏ sẵn lúc mở, báo lỗi
                     khi rời ô, hết lỗi ngay khi sửa đúng (xem composables/validationRules.js).
                     Bấm Lưu (hoặc Enter) mà còn ô sai thì chặn và cuộn tới ô đó. -->
                <v-form
                    v-if="validation"
                    ref="formRef"
                    validate-on="blur invalid-input lazy"
                    @submit.prevent="onSubmit"
                >
                    <slot />
                </v-form>
                <slot v-else />
            </v-card-text>

            <footer class="form-dialog-actions">
                <div v-if="$slots['footer-note']" class="form-dialog-note">
                    <slot name="footer-note" />
                </div>
                <v-spacer />
                <!-- Module tự thay bộ nút nếu cần (vd: thêm nút Xóa) -->
                <slot name="actions">
                    <v-btn variant="outlined" :disabled="loading" @click="close">
                        {{ cancelLabel }}
                    </v-btn>
                    <v-btn
                        :color="submitColor"
                        variant="flat"
                        class="px-5"
                        :loading="loading"
                        :disabled="submitDisabled"
                        @click="onSubmit"
                    >
                        {{ submitLabel }}
                    </v-btn>
                </slot>
            </footer>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, nextTick, ref, watch } from "vue";
const props = defineProps({
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
    // (Không còn hiển thị — header mới chỉ gồm icon + tiêu đề + mô tả. Giữ prop
    // để các form cũ đang truyền eyebrow không báo lỗi.)
    eyebrow: {
        type: String,
        default: "",
    },
    // Icon MDI hiện trong ô vuông cạnh tiêu đề. Đặt "" để bỏ hẳn ô icon.
    icon: {
        type: String,
        default: "mdi-note-edit-outline",
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
    // Chiều rộng tuỳ ý (ưu tiên hơn size) — giữ để tương thích code cũ.
    maxWidth: {
        type: [String, Number],
        default: null,
    },
    // Bật kiểm tra `:rules` của các ô trước khi phát sự kiện submit. Form tự dựng
    // `v-form` riêng (vd EmployeeForm) đặt false để khỏi lồng 2 form.
    validation: {
        type: Boolean,
        default: true,
    },
    // Cỡ chuẩn: sm 480 · md 640 · lg 800 · xl 1000
    size: {
        type: String,
        default: "md",
    },
});

const emit = defineEmits(["update:modelValue", "submit"]);

const formRef = ref(null);

async function onSubmit() {
    if (props.validation && formRef.value) {
        const { valid } = await formRef.value.validate();
        if (!valid) {
            return;
        }
    }
    emit("submit");
}

// Mở lại dialog thì xóa trạng thái lỗi còn sót của lần trước.
watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            nextTick(() => formRef.value?.resetValidation());
        }
    },
);

const SIZES = { sm: 480, md: 640, lg: 800, xl: 1000 };
const dialogWidth = computed(() => props.maxWidth ?? SIZES[props.size] ?? SIZES.md);

function close() {
    emit("update:modelValue", false);
}
</script>

<style scoped>
.form-dialog-card {
    overflow: hidden;
}
.form-dialog-head {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 20px 56px 18px 24px;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    background: rgb(var(--v-theme-surface));
}
.form-dialog-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    color: rgb(var(--v-theme-brand-text));
    background: rgba(var(--v-theme-primary), 0.08);
}
.form-dialog-title {
    margin: 0;
    font-size: 18px;
    line-height: 26px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink));
}
.form-dialog-subtitle {
    margin: 2px 0 0;
    font-size: 14px;
    line-height: 20px;
    color: rgb(var(--v-theme-ink-muted));
}
.form-dialog-close {
    position: absolute;
    top: 14px;
    right: 14px;
}
.form-dialog-body.v-card-text {
    padding: 24px !important;
}
.form-dialog-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 14px 24px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
    background: rgb(var(--v-theme-surface));
}
.form-dialog-note {
    font-size: 12px;
    color: rgb(var(--v-theme-ink-muted));
}
.min-width-0 {
    min-width: 0;
}
</style>
