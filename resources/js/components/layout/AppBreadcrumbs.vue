<script setup>
import { computed } from "vue";
import { useRoute, useRouter } from "vue-router";

const route = useRoute();
const router = useRouter();

// Nhãn hiển thị lấy từ `meta.title` khai trong router/index.js — KHÔNG dùng
// `route.name` vì tên route là mã tiếng Anh ("departments") và sẽ lòi ra giao
// diện. Route nào quên khai `meta.title` thì rơi về tên route để không vỡ
// breadcrumb, nhưng đó là dấu hiệu cần bổ sung.
const labelOf = (target) => target?.meta?.title ?? target?.name ?? "";

const items = computed(() => {
    const crumbs = [{ title: "Trang chủ", to: "/", disabled: false }];

    // Đang đứng ngay trang chủ thì không lặp lại chính nó.
    if (route.path === "/") {
        crumbs[0].disabled = true;
        return crumbs;
    }

    // Trang con khai `meta.parent` để chèn cấp giữa, ví dụ chi tiết nhân viên:
    // Trang chủ / Nhân viên / Chi tiết nhân viên.
    if (route.meta.parent) {
        const parent = router
            .getRoutes()
            .find((item) => item.name === route.meta.parent);

        if (parent) {
            crumbs.push({
                title: labelOf(parent),
                to: parent.path,
                disabled: false,
            });
        }
    }

    crumbs.push({ title: labelOf(route), disabled: true });

    return crumbs;
});
</script>

<template>
    <v-breadcrumbs :items="items" density="compact" class="pa-0" />
</template>
