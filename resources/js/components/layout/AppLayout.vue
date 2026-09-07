<script setup>
import { ref } from "vue";
import AppHeader from "./AppHeader.vue";
import AppSidebar from "./AppSidebar.vue";
import AppBreadcrumbs from "./AppBreadcrumbs.vue";

// "rail" (thu gọn còn icon) là trạng thái dùng chung giữa AppHeader (nút bấm)
// và AppSidebar (nơi hiển thị) — 2 component ngang hàng, không phải cha-con,
// nên phải giữ state ở đây rồi truyền xuống, không thể để mỗi bên tự giữ riêng.
const rail = ref(true);
</script>

<template>
    <AppHeader @toggle-sidebar="rail = !rail" />
    <AppSidebar v-model:rail="rail" />
    <v-main style="background: rgb(var(--v-theme-background))">
        <v-container fluid class="pa-6">
            <AppBreadcrumbs class="mb-4" />
            <slot />
        </v-container>
    </v-main>
</template>
