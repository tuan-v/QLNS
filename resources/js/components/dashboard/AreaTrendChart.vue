<template>
    <VueApexCharts
        type="area"
        height="220"
        :options="options"
        :series="series"
    />
</template>

<script setup>
import { computed } from "vue";
import { useTheme } from "vuetify";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    categories: { type: Array, default: () => [] },
    data: { type: Array, default: () => [] },
});

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);
// design.md: biểu đồ dùng màu brand (primary của theme).
const accent = computed(() => theme.global.current.value.colors.primary);
const axisColor = computed(() =>
    isDark.value ? "#9E9FA0" : "#667085",
);

const series = computed(() => [{ name: "Tỷ lệ đi làm", data: props.data }]);

const options = computed(() => ({
    chart: {
        fontFamily: "'Lexend Variable', Lexend, sans-serif",
        toolbar: { show: false },
        background: "transparent",
        zoom: { enabled: false },
    },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: [accent.value],
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 2 },
    markers: { size: 0, hover: { size: 4 } },
    fill: {
        type: "gradient",
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.35,
            opacityTo: 0,
            stops: [0, 90, 100],
        },
    },
    grid: {
        borderColor: isDark.value ? "#353537" : "#E4E7EC",
        strokeDashArray: 0,
        yaxis: { lines: { show: true } },
    },
    xaxis: {
        categories: props.categories,
        labels: { style: { colors: axisColor.value } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: {
            style: { colors: axisColor.value },
            formatter: (v) => `${Math.round(v)}%`,
        },
    },
    tooltip: { y: { formatter: (v) => `${v}%` } },
}));
</script>
