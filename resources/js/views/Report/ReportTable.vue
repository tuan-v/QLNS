<template>
    <v-sheet class="border rounded-lg glass-panel" color="transparent">
        <div v-if="title" class="px-5 pt-4 pb-1 font-weight-medium">{{ title }}</div>
        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th v-for="h in headers" :key="h.key" :class="{ num: h.kind }">{{ h.title }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(item, index) in items" :key="index">
                        <td v-for="h in headers" :key="h.key" :class="{ num: h.kind }">
                            {{ format(item[h.key], h.kind) }}
                        </td>
                    </tr>
                    <tr v-if="!items.length">
                        <td :colspan="headers.length" class="text-center text-medium-emphasis py-6">
                            Chưa có dữ liệu.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </v-sheet>
</template>

<script setup>
// Bảng số liệu của module Báo cáo — bảng HTML gọn, cột số canh phải.
defineProps({
    headers: { type: Array, required: true }, // [{ title, key, kind?: int | num | pct | money }]
    items: { type: Array, default: () => [] },
    title: { type: String, default: "" },
});

function format(value, kind) {
    if (value === null || value === undefined) return "—";
    const n = Number(value);
    switch (kind) {
        case "int":
            return n.toLocaleString("vi-VN", { maximumFractionDigits: 0 });
        case "num":
            return n.toLocaleString("vi-VN", { maximumFractionDigits: 1 });
        case "pct":
            return `${n.toLocaleString("vi-VN", { maximumFractionDigits: 1 })}%`;
        case "money":
            return `${n.toLocaleString("vi-VN", { maximumFractionDigits: 0 })} ₫`;
        default:
            return value;
    }
}
</script>

<style scoped>
.table-wrap {
    overflow-x: auto;
}
.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.report-table th,
.report-table td {
    padding: 10px 20px;
    text-align: left;
    white-space: nowrap;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
}
.report-table th {
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
}
.report-table tbody tr:last-child td {
    border-bottom: 0;
}
.report-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
</style>
