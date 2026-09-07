<script setup>
import { useLoadingStore } from "../../stores/useLoadingStore";

// Thanh loading mảnh ghim trên cùng màn hình, hiện khi có request axios đang
// chạy hoặc đang chuyển trang. Gắn một lần duy nhất trong App.vue.
const loading = useLoadingStore();
</script>

<template>
    <v-progress-linear
        v-show="loading.active"
        indeterminate
        color="primary"
        height="3"
        class="app-loading-bar"
    />
</template>

<style scoped>
/* z-index phải cao hơn lớp phủ của v-dialog (Vuetify dùng 2400), nếu không
   thanh loading sẽ bị dialog che mất đúng lúc đang lưu dữ liệu. */
.app-loading-bar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 3000;
}
</style>
