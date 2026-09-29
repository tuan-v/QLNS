<template>
    <div class="d-flex flex-column align-center text-center">
        <div class="text-body-2 font-weight-bold mb-2">{{ title }}</div>
        <VueApexCharts type="radialBar" :options="options" :series="[value]" width="148" />
        <div v-if="sub" class="text-caption mt-1" style="opacity: 0.65">{{ sub }}</div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { useTheme } from "vuetify";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    title: { type: String, default: "" },
    value: { type: Number, default: 0 },
    sub: { type: String, default: "" },
    color: { type: String, default: "primary" },
});

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);

const options = computed(() => ({
    chart: { sparkline: { enabled: true }, fontFamily: "'Lexend Variable', Lexend, sans-serif" },
    plotOptions: {
        radialBar: {
            hollow: { size: "62%" },
            track: { background: isDark.value ? "#29292B" : "#F2F4F7" },
            dataLabels: {
                name: { show: false },
                value: {
                    fontSize: "22px",
                    fontWeight: 600,
                    color: isDark.value ? "#F7F7F8" : "#101828",
                    offsetY: 8,
                    formatter: (val) => `${val}%`,
                },
            },
        },
    },
    colors: [theme.global.current.value.colors[props.color] ?? theme.global.current.value.colors.primary],
    stroke: { lineCap: "round" },
}));
</script>
