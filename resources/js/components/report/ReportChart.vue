<template>
    <VueApexCharts :type="type" :height="height" :options="options" :series="series" />
</template>

<script setup>
import { computed } from "vue";
import { useTheme } from "vuetify";
import VueApexCharts from "vue3-apexcharts";

// Biểu đồ cột / đường cho module Báo cáo — cùng phông, màu, lưới với AreaTrendChart/DonutChart
// của Dashboard để các trang trông đồng bộ.
const props = defineProps({
    type: { type: String, default: "bar" }, // bar | line
    categories: { type: Array, default: () => [] },
    // [{ name, data: [] }]
    series: { type: Array, default: () => [] },
    // number | percent | money
    format: { type: String, default: "number" },
    stacked: { type: Boolean, default: false },
    horizontal: { type: Boolean, default: false },
    height: { type: [Number, String], default: 280 },
    colors: { type: Array, default: null },
});

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);
const axisColor = computed(() => (isDark.value ? "rgba(255,255,255,0.6)" : "#667085"));

const formatValue = (value) => {
    const n = Number(value ?? 0);
    if (props.format === "percent") return `${n.toLocaleString("vi-VN", { maximumFractionDigits: 1 })}%`;
    if (props.format === "money") {
        if (Math.abs(n) >= 1e9) return `${(n / 1e9).toLocaleString("vi-VN", { maximumFractionDigits: 2 })} tỷ`;
        if (Math.abs(n) >= 1e6) return `${(n / 1e6).toLocaleString("vi-VN", { maximumFractionDigits: 1 })} tr`;
        return n.toLocaleString("vi-VN");
    }
    return n.toLocaleString("vi-VN", { maximumFractionDigits: 1 });
};

const fullValue = (value) =>
    props.format === "money" ? `${Number(value ?? 0).toLocaleString("vi-VN")} ₫` : formatValue(value);

const options = computed(() => ({
    chart: {
        fontFamily: "'Lexend Variable', Lexend, sans-serif",
        toolbar: { show: false },
        background: "transparent",
        zoom: { enabled: false },
        stacked: props.stacked,
    },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors:
        props.colors ??
        (isDark.value ? ["#7592FF", "#F79009", "#12B76A", "#F04438"] : ["#465FFF", "#F79009", "#12B76A", "#F04438"]),
    plotOptions: {
        bar: {
            borderRadius: 4,
            borderRadiusApplication: "end",
            borderRadiusWhenStacked: "last",
            columnWidth: "45%",
            horizontal: props.horizontal,
            barHeight: "60%",
        },
    },
    dataLabels: { enabled: false },
    stroke: props.type === "line" ? { curve: "straight", width: 2 } : { width: 0 },
    markers: { size: props.type === "line" ? 3 : 0 },
    grid: {
        borderColor: isDark.value ? "#353537" : "#E4E7EC",
        yaxis: { lines: { show: !props.horizontal } },
        xaxis: { lines: { show: props.horizontal } },
    },
    legend: { position: "top", horizontalAlign: "left", labels: { colors: axisColor.value } },
    xaxis: {
        categories: props.categories,
        labels: { style: { colors: axisColor.value }, formatter: props.horizontal ? formatValue : undefined },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: {
            style: { colors: axisColor.value },
            formatter: props.horizontal ? undefined : formatValue,
            maxWidth: 160,
        },
        // Tỷ lệ % (kể cả cột chồng: đi làm + nghỉ phép + vắng = 100%) luôn trên thang 0-100.
        max: props.format === "percent" ? 100 : undefined,
        min: 0,
    },
    tooltip: { y: { formatter: fullValue } },
}));
</script>
