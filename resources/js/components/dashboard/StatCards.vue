<script setup>
defineProps({
    stats: {
        type: Array,
        default: () => [],
    },
});

// Chỉ những màu THEME thật (có biến --v-theme-{tên}, xem plugins/vuetify.js)
// mới an toàn để tự ghép rgb(var(--v-theme-...)) cho box-shadow — màu tĩnh
// kiểu "purple"/"teal"/"indigo"/"default" (StatCards vẫn nhận, xem
// AttendanceOverview.vue/Employees.vue) KHÔNG có biến này, ghép bừa sẽ ra
// CSS rỗng/sai. Icon vẫn tô đúng màu qua class Vuetify "bg-{color}"/
// "text-{color}" (an toàn cho MỌI tên màu) — chỉ riêng hiệu ứng đổ bóng nhuốm
// màu là bỏ qua (không glow) với nhóm màu tĩnh này, thay vì hiện sai.
const THEME_COLORS = [
    "primary",
    "secondary",
    "success",
    "warning",
    "error",
    "info",
];

// Bóng đổ dùng MÀU ĐẶC 100% trước đó bị chói (phản hồi người dùng: "lóa
// mắt") — hạ xuống còn 40% alpha + giảm độ lan (spread) để chỉ còn gợi ý độ
// sâu, không thành 1 quầng sáng màu.
function glowShadow(color) {
    return THEME_COLORS.includes(color)
        ? `0 6px 14px -8px rgba(var(--v-theme-${color}), 0.4)`
        : "none";
}
</script>

<template>
    <v-row class="mt-2">
        <v-col v-for="stat in stats" :key="stat.label" cols="12" sm="6" md="3">
            <v-card variant="flat" rounded="lg" class="pa-4 stat-card border">
                <!-- "Ánh màu" nhẹ phủ theo màu của thẻ (theo phản hồi người dùng:
             "không đậm nhưng không nhạt nhòa") — dùng class bg-{color} có
             sẵn của Vuetify (đúng màu dù theme color hay màu tĩnh) rồi hạ
             opacity + che dần bằng mask, không tự ghép giá trị màu. -->
                <div class="stat-card-glow" :class="`bg-${stat.color}`" />

                <div
                    class="d-flex align-center justify-space-between stat-card-content"
                >
                    <div
                        class="text-h5 font-weight-bold"
                        :class="`text-${stat.color}`"
                    >
                        {{ stat.value }}
                    </div>
                    <v-avatar
                        :color="stat.color"
                        variant="tonal"
                        rounded="lg"
                        size="40"
                        :style="{ boxShadow: glowShadow(stat.color) }"
                    >
                        <v-icon :icon="stat.icon" size="20" />
                    </v-avatar>
                </div>
                <div
                    class="text-caption mt-2 stat-card-content"
                    style="opacity: 0.7"
                >
                    {{ stat.label }}
                </div>
            </v-card>
        </v-col>
    </v-row>
</template>

<style scoped>
.stat-card {
    position: relative;
    overflow: hidden;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}

.stat-card-glow {
    position: absolute;
    inset: 0;
    /* 0.5 (tự chỉnh) bị chói — không chỉ do opacity cao, mà vùng chuyển màu
       (mask) dừng quá sớm (62%) nên màu dồn thành 1 "điểm nóng" gắt ở góc
       icon. Hạ opacity xuống mức vừa phải HƠN NỮA, đồng thời kéo dài điểm
       kết thúc mask ra 92% để màu tỏa đều, dịu hơn thay vì tụ 1 chỗ. */
    opacity: 0.26;
    -webkit-mask-image: linear-gradient(155deg, black 0%, transparent 100%);
    mask-image: linear-gradient(155deg, black 0%, transparent 100%);
    pointer-events: none;
}

/* Nội dung thật phải nổi TRÊN lớp ánh màu (lớp glow dùng position:absolute
   phủ kín thẻ). */
.stat-card-content {
    position: relative;
}
</style>
