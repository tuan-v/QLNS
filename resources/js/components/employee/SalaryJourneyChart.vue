<template>
    <div v-if="!payslips.length" class="text-body-2 py-6 text-center" style="opacity: 0.6">Chưa có kỳ lương nào.</div>
    <VueApexCharts v-else type="line" height="300" :options="options" :series="series" />
</template>

<script setup>
// Lương thực nhận TỪNG THÁNG của tab "Hành trình" (2026-10-22): cột = thực nhận, đường bậc = mức lương hợp
// đồng của tháng đó để so. Rê chuột vào cột: công, lương cơ bản, phụ cấp, OT, thưởng, bảo hiểm, thuế.
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

const money = (v) => `${Math.round(v ?? 0).toLocaleString("vi-VN")} ₫`;
const short = (v) => (v >= 1_000_000 ? `${(v / 1_000_000).toLocaleString("vi-VN", { maximumFractionDigits: 1 })} tr` : money(v));
const label = (p) => `T${p.period_month}/${p.period_year}`;

// Mức lương hợp đồng áp dụng giữa tháng đó (hợp đồng bắt đầu muộn nhất trước ngày 15).
function contractSalaryFor(p) {
    const mid = `${p.period_year}-${String(p.period_month).padStart(2, "0")}-15`;
    const row = [...props.salaryHistory].reverse().find((r) => r.start_date <= mid) ?? props.salaryHistory[0];
    return row?.salary ?? null;
}

const series = computed(() => [
    { name: "Thực nhận", type: "column", data: props.payslips.map((p) => p.net_salary) },
    { name: "Lương hợp đồng", type: "line", data: props.payslips.map(contractSalaryFor) },
]);

function tooltip({ dataPointIndex }) {
    const p = props.payslips[dataPointIndex];
    const rows = [
        ["Công", `${p.actual_work_days}/${p.standard_work_days} ngày`],
        ["Lương cơ bản (theo công)", money(p.base_salary)],
        p.total_allowance ? ["+ Phụ cấp", money(p.total_allowance)] : null,
        p.overtime_amount ? ["+ Làm thêm giờ", money(p.overtime_amount)] : null,
        p.bonus_amount ? ["+ Thưởng", money(p.bonus_amount)] : null,
        p.leave_payout_amount ? ["+ Tiền phép", money(p.leave_payout_amount)] : null,
        p.insurance_amount ? ["− Bảo hiểm", money(p.insurance_amount)] : null,
        p.personal_income_tax ? ["− Thuế TNCN", money(p.personal_income_tax)] : null,
        p.other_deduction ? ["− Khấu trừ khác", money(p.other_deduction)] : null,
    ].filter(Boolean);
    const contract = contractSalaryFor(p);
    return `<div class="salary-tip">
        <div class="salary-tip__title">${label(p)}${p.payroll_status !== "closed" && p.payroll_status !== "paid" ? " · chưa chốt" : ""}</div>
        ${rows.map(([k, v]) => `<div class="salary-tip__row"><span>${k}</span><span>${v}</span></div>`).join("")}
        <div class="salary-tip__row salary-tip__total"><span>Thực nhận</span><span>${money(p.net_salary)}</span></div>
        ${contract ? `<div class="salary-tip__hint">Lương hợp đồng ${money(contract)}</div>` : ""}
    </div>`;
}

const options = computed(() => ({
    chart: {
        fontFamily: "'Lexend Variable', Lexend, sans-serif",
        toolbar: { show: false },
        background: "transparent",
        zoom: { enabled: false },
    },
    theme: { mode: isDark.value ? "dark" : "light" },
    colors: [theme.global.current.value.colors.success, theme.global.current.value.colors.primary],
    stroke: { curve: "stepline", width: [0, 2], dashArray: [0, 5] },
    plotOptions: { bar: { columnWidth: props.payslips.length < 4 ? "28%" : "55%", borderRadius: 4 } },
    markers: { size: [0, 0] },
    dataLabels: {
        enabled: true,
        enabledOnSeries: [0],
        formatter: short,
        offsetY: -18,
        style: { fontSize: "11px", fontWeight: 600, colors: [axisColor.value] },
        background: { enabled: false },
    },
    legend: { position: "top", horizontalAlign: "left", labels: { colors: axisColor.value } },
    grid: { borderColor: isDark.value ? "#353537" : "#E4E7EC" },
    xaxis: {
        categories: props.payslips.map(label),
        labels: { style: { colors: axisColor.value } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        min: 0,
        forceNiceScale: true,
        labels: { style: { colors: axisColor.value }, formatter: short },
    },
    tooltip: { shared: true, intersect: false, custom: tooltip },
}));
</script>

<style>
/* Không scoped: tooltip ApexCharts render ngoài component. */
.salary-tip {
    padding: 10px 12px;
    min-width: 240px;
    font-size: 12px;
}
.salary-tip__title {
    font-weight: 700;
    margin-bottom: 6px;
}
.salary-tip__row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 1px 0;
}
.salary-tip__total {
    margin-top: 4px;
    padding-top: 4px;
    border-top: 1px solid rgba(128, 128, 128, 0.3);
    font-weight: 700;
}
.salary-tip__hint {
    margin-top: 4px;
    opacity: 0.7;
}
</style>
