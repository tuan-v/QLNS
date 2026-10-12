<template>
    <VueApexCharts type="line" height="280" :options="options" :series="series" />
</template>

<script setup>
// Biểu đồ lương của tab "Hành trình": bậc thang = lương thỏa thuận theo từng hợp đồng,
// đường = lương thực nhận từng kỳ lương. Trục X theo thời gian thật.
import { computed } from "vue";
import { useTheme } from "vuetify";
import VueApexCharts from "vue3-apexcharts";

const props = defineProps({
    salaryHistory: { type: Array, default: () => [] },
    payslips: { type: Array, default: () => [] },
});

const theme = useTheme();
const isDark = computed(() => theme.global.current.value.dark);
const axisColor = computed(() => (isDark.value ? "#9E9FA0" : "#667085"));

const toTime = (iso) => new Date(`${iso}T00:00:00`).getTime();

const series = computed(() => {
    // Kéo bậc lương hiện tại tới hôm nay (hoặc ngày kết thúc HĐ) để thấy rõ giai đoạn.
    const steps = props.salaryHistory.map((row) => ({ x: toTime(row.start_date), y: row.salary }));
    const last = props.salaryHistory.at(-1);
    if (last) {
        const end = last.end_date && toTime(last.end_date) < Date.now() ? toTime(last.end_date) : Date.now();
        steps.push({ x: end, y: last.salary });
    }
    return [
        { name: "Lương thỏa thuận (hợp đồng)", data: steps },
        {
            name: "Lương thực nhận",
            data: props.payslips.map((p) => ({ x: new Date(p.period_year, p.period_month - 1, 15).getTime(), y: p.net_salary })),
        },
    ];
});

const money = (v) => `${Math.round(v).toLocaleString("vi-VN")} ₫`;

const options = computed(() => ({
    chart: {
        fontFamily: "'Lexend Variable', Lexend, sans-serif",
        toolbar: { show: false },
        background: "transparent",
        zoom: { enabled: false },
    },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: [theme.global.current.value.colors.primary, theme.global.current.value.colors.success],
    stroke: { curve: ["stepline", "smooth"], width: [3, 2] },
    markers: { size: [4, 4], hover: { size: 6 } },
    dataLabels: { enabled: false },
    legend: { position: "top", horizontalAlign: "left", labels: { colors: axisColor.value } },
    grid: { borderColor: isDark.value ? "#353537" : "#E4E7EC" },
    xaxis: {
        type: "datetime",
        // Giai đoạn ngắn (vài tháng) mà để "MM/yyyy" thì nhãn lặp lại -> ngày/tháng/năm, tối đa 6 mốc.
        tickAmount: 6,
        labels: { style: { colors: axisColor.value }, datetimeUTC: false, format: "dd/MM/yy" },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        labels: {
            style: { colors: axisColor.value },
            formatter: (v) => (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)} tr` : Math.round(v).toLocaleString("vi-VN")),
        },
    },
    tooltip: { x: { format: "dd/MM/yyyy" }, y: { formatter: money } },
    noData: { text: "Chưa có dữ liệu lương" },
}));
</script>
