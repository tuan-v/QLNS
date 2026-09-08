<template>
    <v-dialog
        :model-value="modelValue"
        :max-width="maxWidth"
        scrollable
        persistent
        transition="fade-transition"
        @update:model-value="close"
    >
        <v-card
            rounded="xl"
            elevation="12"
            class="glass-panel overflow-hidden form-dialog-card"
        >
            <div class="form-dialog-banner px-6 pt-5 pb-4">
                <div class="d-flex align-start ga-3">
                    <!-- Ô icon đầu dialog: cho khối tiêu đề một điểm neo thị
                         giác thay vì chỉ có chữ trôi trên nền gradient. -->
                    <div v-if="icon" class="form-dialog-icon">
                        <v-icon :icon="icon" size="20" />
                    </div>

                    <div class="min-width-0">
                        <div v-if="eyebrow" class="form-dialog-eyebrow">
                            {{ eyebrow }}
                        </div>
                        <div class="text-h6 font-weight-bold mt-1">
                            {{ title }}
                        </div>
                        <div v-if="subtitle" class="form-dialog-subtitle mt-1">
                            {{ subtitle }}
                        </div>
                    </div>
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
            </div>

            <v-card-text class="px-6 py-5">
                <!-- Lỗi chung hiện ra bằng hiệu ứng mở dần: alert bật đột ngột
                     làm cả form giật xuống một đoạn, người dùng dễ mất dấu ô
                     đang nhập. -->
                <v-expand-transition>
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
                </v-expand-transition>

                <!-- Các khối/ô nhập riêng của từng module đặt ở đây -->
                <slot />
            </v-card-text>

            <v-divider />

            <v-card-actions class="px-6 py-4 form-dialog-actions">
                <div v-if="$slots['footer-note']" class="form-dialog-note">
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

/* Vệt sáng mờ ở góc trên phải: giữ đúng tinh thần Glassmorphism của mục 6 tài
   liệu, làm dải gradient bớt "phẳng" mà không thêm màu nào ngoài bảng màu. */
.form-dialog-banner::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(
        120% 100% at 100% 0%,
        rgba(255, 255, 255, 0.16),
        transparent 60%
    );
}

/* Nội dung banner phải nằm trên vệt sáng ::after */
.form-dialog-banner > * {
    position: relative;
    z-index: 1;
}

.form-dialog-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    color: #fff;
    background: rgba(255, 255, 255, 0.16);
    border: 1px solid rgba(255, 255, 255, 0.22);
    backdrop-filter: blur(4px);
}

.form-dialog-eyebrow {
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    opacity: 0.75;
}

.form-dialog-subtitle {
    font-size: 0.75rem;
    line-height: 1.4;
    opacity: 0.8;
}

.min-width-0 {
    min-width: 0;
}

/* Nút đóng dạng ghost thay vì nút trắng đặc: nền trắng đặc trên dải gradient
   hút mắt hơn cả tiêu đề, trong khi đây chỉ là thao tác phụ. */
.form-dialog-close {
    position: absolute;
    top: 12px;
    right: 12px;
    color: #fff;
    opacity: 0.85;
    transition: background-color 0.15s ease, opacity 0.15s ease;
}

.form-dialog-close:hover {
    opacity: 1;
    background: rgba(255, 255, 255, 0.16);
}

/* Chân dialog chìm hơn thân một chút để tách vùng thao tác khỏi vùng nhập liệu. */
.form-dialog-actions {
    background: rgba(var(--v-theme-on-surface), 0.03);
}

.form-dialog-note {
    font-size: 0.75rem;
    opacity: 0.65;
}

/* Nút nhún nhẹ khi bấm — phản hồi xúc giác cho thao tác chính. Chỉ áp trong
   dialog này, không đụng tới nút của toàn app. */
.form-dialog-actions :deep(.v-btn) {
    transition:
        transform 0.12s ease,
        box-shadow 0.2s ease;
}

.form-dialog-actions :deep(.v-btn:active) {
    transform: scale(0.97);
}

.form-dialog-actions :deep(.v-btn--variant-flat:hover) {
    box-shadow: 0 8px 20px rgba(var(--v-theme-primary), 0.28);
}

/* Chuyển động lúc mở: trượt lên + phóng nhẹ theo đường cong có độ nảy, cho
   cảm giác "bật ra" mượt đúng yêu cầu Premium UX/UI ở mục 6 tài liệu.

   Đặt bằng `animation` ngay trên thẻ card của component, thay vì tự viết một
   transition riêng cho `<v-dialog>`. Lý do: lớp phủ do chính Vuetify dựng nằm
   ngoài template này, muốn tạo hiệu ứng ở đó phải viết CSS không scoped và phụ
   thuộc vào cơ chế gắn/gỡ lớp `*-enter-from` của Vue. Để chuyển động ở card thì
   nó độc lập hẳn — đổi prop `transition` của dialog sau này cũng không ảnh
   hưởng, và card được mount lại mỗi lần mở nên animation luôn chạy đúng.
   Phần mờ dần của lớp phủ giao cho `fade-transition` có sẵn của Vuetify. */
@keyframes form-dialog-in {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.97);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.form-dialog-card {
    animation: form-dialog-in 0.32s cubic-bezier(0.22, 1.2, 0.36, 1);
}

@media (prefers-reduced-motion: reduce) {
    .form-dialog-card {
        animation: none;
    }

    .form-dialog-actions :deep(.v-btn) {
        transition: none;
    }

    .form-dialog-actions :deep(.v-btn:active) {
        transform: none;
    }
}
</style>

