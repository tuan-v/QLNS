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
});

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);

const palette = computed(() => {
    const c = theme.global.current.value.colors;
    return [c.primary, c.success, "#f59e0b", "#38bdf8", "#a78bfa", "#f43f5e"];
});

const series = computed(() => props.data);
const legendColor = computed(() => (isDark.value ? "rgba(255,255,255,0.75)" : "rgba(0,0,0,0.72)"));

const options = computed(() => ({
    labels: props.labels,
    chart: { background: "transparent" },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: palette.value,
    legend: { position: "bottom", labels: { colors: legendColor.value } },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: {
        pie: {
            donut: {
                size: "68%",
                labels: {
                    show: true,
                    total: { show: true, label: props.totalLabel, color: isDark.value ? "#fff" : undefined },
                },
            },
        },
    },
}));
</script>
