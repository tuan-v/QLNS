<script setup>
import { ref, watch } from "vue";
import { useDisplay } from "vuetify";
import AppHeader from "./AppHeader.vue";
import AppSidebar from "./AppSidebar.vue";
import AppBreadcrumbs from "./AppBreadcrumbs.vue";

const { mobile } = useDisplay();

// 2 khái niệm KHÁC NHAU, dùng chung 1 nút hamburger (Ngày 44): Desktop —
// sidebar luôn hiện (permanent), "rail" chỉ thu gọn còn icon. Mobile —
// sidebar ẩn HẲN mặc định (temporary, đè lên nội dung như overlay) để trả
// lại toàn bộ bề ngang màn hình cho nội dung (trước đó rail-width="80" vẫn
// chiếm cứng ~20% màn hình điện thoại mọi lúc), "drawerOpen" là bật/tắt
// overlay đó. Cả 2 đều dùng chung state ở đây vì AppHeader/AppSidebar là 2
// component ngang hàng, không phải cha-con.
//
// Lỗi đã vấp: khởi tạo drawerOpen=false cố định rồi trông cậy `permanent`
// của v-navigation-drawer tự ép hiện lại trên desktop — SAI. Vuetify chỉ tự
// suy ra "permanent thì luôn hiện" khi component KHÔNG nhận `v-model` tường
// minh (modelValue == null); ở đây luôn truyền 1 ref thật (dù là false) nên
// cơ chế tự suy đó không chạy, và 1 watcher nội bộ khác của Vuetify (ép
// isActive=true khi prop `permanent` chuyển từ false sang true) CHỈ bắn khi
// có sự CHUYỂN TRẠNG THÁI — nếu trang tải thẳng ở kích thước desktop ổn định
// (không có bước "mobile" thoáng qua nào để permanent chuyển true), watcher
// đó không bao giờ chạy, sidebar bị ẩn vĩnh viễn dù đúng là desktop. Đã xác
// nhận bằng Playwright: `.v-navigation-drawer--active` = 0 ở MỌI viewport
// (kể cả 1920px) trước khi sửa. Fix: tự tính giá trị khởi tạo theo `mobile`
// NGAY LÚC KHAI BÁO thay vì hằng số cố định, và tự đồng bộ lại mỗi khi
// `mobile` đổi (resize qua lại breakpoint) — không dựa vào cơ chế ngầm của
// Vuetify nữa.
const rail = ref(true);
const drawerOpen = ref(!mobile.value);

watch(mobile, (isMobile) => {
    drawerOpen.value = !isMobile;
});

function toggleSidebar() {
    if (mobile.value) {
        drawerOpen.value = !drawerOpen.value;
    } else {
        rail.value = !rail.value;
    }
}
</script>

<template>
    <AppHeader @toggle-sidebar="toggleSidebar" />
    <AppSidebar v-model="drawerOpen" v-model:rail="rail" />
    <v-main style="background: rgb(var(--v-theme-background))">
        <v-container fluid class="pa-3 pa-md-6">
            <AppBreadcrumbs class="mb-4" />
            <slot />
        </v-container>
    </v-main>
</template>
