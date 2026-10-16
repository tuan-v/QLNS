<template>
    <VueApexCharts type="donut" height="230" :options="options" :series="series" />
</template>

<script setup>
import { computed } from "vue";
import { useTheme } from "vuetify";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    labels: { type: Array, default: () => [] },
    data: { type: Array, default: () => [] },
    totalLabel: { type: String, default: "Tổng" },
    // "money": giữa vòng hiện gọn (8,5 tr / 1,2 tỷ), tooltip hiện đủ "8.522.727 ₫" — dùng ở trang Báo cáo.
    format: { type: String, default: "number" },
});

const short = (value) => {
    const n = Number(value ?? 0);
    if (props.format !== "money") return n.toLocaleString("vi-VN", { maximumFractionDigits: 1 });
    if (Math.abs(n) >= 1e9) return `${(n / 1e9).toLocaleString("vi-VN", { maximumFractionDigits: 2 })} tỷ`;
    if (Math.abs(n) >= 1e6) return `${(n / 1e6).toLocaleString("vi-VN", { maximumFractionDigits: 1 })} tr`;
    return n.toLocaleString("vi-VN");
};
const full = (value) =>
    props.format === "money" ? `${Math.round(Number(value ?? 0)).toLocaleString("vi-VN")} ₫` : short(value);

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);

const palette = computed(() => {
    // Thang brand theo design.md (TailAdmin brand-500 → 200) + 2 màu phụ,
    // khác nhau về ĐỘ SÁNG để phân biệt được cả khi mù màu.
    return isDark.value
        ? ["#7592FF", "#465FFF", "#9CB9FF", "#3641F5", "#C2D6FF", "#2A31D8"]
        : ["#465FFF", "#9CB9FF", "#3641F5", "#C2D6FF", "#252DAE", "#DDE9FF"];
});

const series = computed(() => props.data);
const legendColor = computed(() => (isDark.value ? "rgba(255,255,255,0.75)" : "rgba(0,0,0,0.72)"));

const options = computed(() => ({
    labels: props.labels,
    chart: { background: "transparent", fontFamily: "'Lexend Variable', Lexend, sans-serif" },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: palette.value,
    legend: { position: "bottom", labels: { colors: legendColor.value } },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: full } },
    stroke: { width: 2, colors: [isDark.value ? "#1C1D1F" : "#FFFFFF"] },
    plotOptions: {
        pie: {
            donut: {
                size: "68%",
                labels: {
                    show: true,
                    value: { formatter: short },
                    total: {
                        show: true,
                        label: props.totalLabel,
                        color: isDark.value ? "#fff" : undefined,
                        formatter: (w) => short(w.globals.seriesTotals.reduce((a, b) => a + b, 0)),
                    },
                },
            },
        },
    },
}));
</script>
