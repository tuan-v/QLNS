<template>
    <VueApexCharts type="area" height="220" :options="options" :series="series" />
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
const accent = computed(() => theme.global.current.value.colors.primary);
const axisColor = computed(() => (isDark.value ? "rgba(255,255,255,0.6)" : "rgba(0,0,0,0.6)"));

const series = computed(() => [{ name: "Tỷ lệ đi làm", data: props.data }]);

const options = computed(() => ({
    chart: { toolbar: { show: false }, background: "transparent", zoom: { enabled: false } },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: [accent.value],
    dataLabels: { enabled: false },
    stroke: { curve: "smooth", width: 3 },
    markers: { size: 4, strokeWidth: 0 },
    fill: {
        type: "gradient",
        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0, stops: [0, 90, 100] },
    },
    grid: { borderColor: isDark.value ? "rgba(255,255,255,0.08)" : "rgba(0,0,0,0.06)", strokeDashArray: 4 },
    xaxis: {
        categories: props.categories,
        labels: { style: { colors: axisColor.value } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor.value }, formatter: (v) => `${Math.round(v)}%` } },
    tooltip: { y: { formatter: (v) => `${v}%` } },
}));
</script>
