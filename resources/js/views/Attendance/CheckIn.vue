<template>
    <CheckInMobile v-if="mobile" />
    <CheckInDesktop v-else />
</template>

<script setup>
// Ngày 44: route "/check-in" giữ nguyên, chỉ còn là bộ chọn giữa 2 giao diện
// khác nhau theo kích thước màn hình — CheckInDesktop.vue (bảng lịch sử) và
// CheckInMobile.vue (danh sách thẻ). Cả 2 dùng CHUNG useCheckIn() nên không
// có rủi ro lệch logic giữa 2 bên (đã bàn với người dùng trước khi tách).
import { useDisplay } from "vuetify";
import CheckInDesktop from "./CheckInDesktop.vue";
import CheckInMobile from "./CheckInMobile.vue";

const { mobile } = useDisplay();
</script>
