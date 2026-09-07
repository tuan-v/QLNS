<script setup>
import { useToastStore } from "../../stores/useToastStore";

// Gắn một lần duy nhất trong App.vue. Mọi nơi khác chỉ cần gọi
// useToastStore().success(...) / .error(...) là thông báo tự hiện.
//
// Component này cố tình "câm": không giữ state, không hẹn giờ — tất cả nằm ở
// store (xem giải thích trong useToastStore.js). `:timeout="-1"` là để Vuetify
// KHÔNG tự quản hẹn giờ, vì đó chính là chỗ hỏng khi dùng <v-snackbar-queue>.
//
// Về giao diện: bỏ hẳn khối màu đặc mặc định của Vuetify (color="transparent"
// + xóa padding của .v-snackbar__content) rồi tự dựng thẻ bên trong theo đúng
// mục 6 tài liệu yêu cầu — glassmorphism, bo góc, màu chỉ dùng để nhấn ở viền
// trái và ô icon chứ không tô kín nền.
const toast = useToastStore();

const ICONS = {
    success: "mdi-check-circle-outline",
    error: "mdi-alert-circle-outline",
    warning: "mdi-alert-outline",
    info: "mdi-information-outline",
};
</script>

<template>
    <!-- v-if theo `current`: khi model-value về false, Vuetify chỉ ẩn nội dung
         chứ KHÔNG tháo phần tử — để nguyên thì còn lại một khung rỗng nằm mãi
         ở góc màn hình. Store xóa `current` sau khi hiệu ứng đóng chạy xong
         nên v-if tháo đúng lúc, không cắt ngang hoạt ảnh. -->
    <v-snackbar
        v-if="toast.current"
        :model-value="toast.visible"
        :timeout="-1"
        color="transparent"
        variant="flat"
        :elevation="0"
        location="top right"
        class="app-toast-root"
        content-class="app-toast-slot"
        @update:model-value="(open) => !open && toast.hide()"
    >
        <div class="app-toast" :class="`app-toast--${toast.current.color}`">
            <span class="app-toast__icon">
                <v-icon :icon="ICONS[toast.current.color]" size="20" />
            </span>

            <span class="app-toast__text">{{ toast.current.text }}</span>

            <v-btn
                icon="mdi-close"
                variant="text"
                size="small"
                density="comfortable"
                class="app-toast__close"
                aria-label="Đóng thông báo"
                @click="toast.hide()"
            />
        </div>
    </v-snackbar>
</template>

<style>
/* Không scoped: `content-class` được Vuetify gắn lên phần tử của chính nó,
   không mang scope id của component này nên style scoped sẽ không với tới. */

/* Vỏ ngoài của snackbar vẫn tự tô nền `surface` + đổ bóng + đặt bề rộng tối
   thiểu, bất kể `color="transparent"`. Ở theme TỐI nó trùng màu nền nên không
   ai thấy, nhưng ở theme SÁNG lộ ra một khối trắng to bọc quanh toast. Phải
   dọn sạch để chỉ còn đúng thẻ kính bên trong. */
.app-toast-root .v-snackbar__wrapper {
    background: transparent !important;
    box-shadow: none !important;
    min-width: 0;
    min-height: 0;
}

.app-toast-slot {
    padding: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
}

.app-toast {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 320px;
    max-width: 440px;
    /* padding trái chừa chỗ cho dải màu 4px của ::before */
    padding: 12px 8px 12px 18px;
    border-radius: 14px;

    /* KHÔNG dùng .glass-panel (nền 72%): ở theme sáng, kính mờ đó đặt trên nền
       trang gần trắng làm chữ phía sau lộ xuyên qua, đọc rất khó. 92% vẫn giữ
       cảm giác kính theo mục 6 nhưng nội dung tách hẳn khỏi nền. */
    background: rgba(var(--v-theme-surface), 0.92);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);

    /* --v-border-color tự đổi giữa hai theme (đen ở sáng, trắng ở tối) nên một
       công thức dùng được cho cả hai. */
    border: 1px solid rgba(var(--v-border-color), 0.18);
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
}

/* Dải màu bên trái là ::before chứ KHÔNG phải border-left: border có màu khác
   thân sẽ bị bo góc cắt vát ở hai đầu, nhìn như viền lỗi. ::before nằm trong
   khối đã `overflow: hidden` nên bo theo góc gọn gàng. */
.app-toast::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: rgb(var(--v-theme-primary));
}

.app-toast__icon {
    flex: none;
    width: 34px;
    height: 34px;
    border-radius: 11px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(var(--v-theme-primary), 0.16);
    color: rgb(var(--v-theme-primary));
}

.app-toast__text {
    flex: 1 1 auto;
    font-size: 0.875rem;
    font-weight: 500;
    line-height: 1.45;
    color: rgb(var(--v-theme-on-surface));
}

.app-toast__close {
    flex: none;
    opacity: 0.5;
}

.app-toast__close:hover {
    opacity: 1;
}

/* Màu chỉ để nhấn: viền trái + ô icon, giữ nền kính chung cho cả 4 loại. */
.app-toast--success::before {
    background: rgb(var(--v-theme-success));
}
.app-toast--success .app-toast__icon {
    background: rgba(var(--v-theme-success), 0.16);
    color: rgb(var(--v-theme-success));
}

.app-toast--error::before {
    background: rgb(var(--v-theme-error));
}
.app-toast--error .app-toast__icon {
    background: rgba(var(--v-theme-error), 0.16);
    color: rgb(var(--v-theme-error));
}

.app-toast--warning::before {
    background: rgb(var(--v-theme-warning));
}
.app-toast--warning .app-toast__icon {
    background: rgba(var(--v-theme-warning), 0.16);
    color: rgb(var(--v-theme-warning));
}

.app-toast--info::before {
    background: rgb(var(--v-theme-info));
}
.app-toast--info .app-toast__icon {
    background: rgba(var(--v-theme-info), 0.16);
    color: rgb(var(--v-theme-info));
}
</style>
