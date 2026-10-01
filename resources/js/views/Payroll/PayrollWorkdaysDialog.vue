<template>
    <v-dialog v-model="open" max-width="960" scrollable>
        <v-card rounded="xl" elevation="12" class="glass-panel">
            <v-card-title class="d-flex align-center pa-5 pb-2">
                <div>
                    <div class="text-h6 font-weight-bold">
                        Chi tiết ngày công —
                        {{ data?.employee?.full_name ?? detail?.employee?.full_name }}
                    </div>
                    <div class="text-body-2 text-medium-emphasis">
                        Tháng {{ payroll?.period_month }}/{{ payroll?.period_year }}
                    </div>
                </div>
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" @click="open = false" />
            </v-card-title>

            <v-card-text class="px-5 pb-5">
                <v-progress-linear v-if="loading" indeterminate class="mb-3" />
                <v-alert v-if="error" type="error" variant="tonal" class="mb-3">
                    {{ error }}
                </v-alert>

                <template v-if="data">
                    <v-alert
                        :type="data.summary.matches_payslip ? 'success' : 'warning'"
                        variant="tonal"
                        density="compact"
                        class="mb-3"
                    >
                        <template v-if="data.summary.matches_payslip">
                            Khớp phiếu lương: tổng các dòng được tính =
                            <strong>{{ data.summary.computed_work_days }}</strong>
                            công = số công trên phiếu lương.
                        </template>
                        <template v-else>
                            <strong>Không khớp:</strong> dữ liệu chấm công hiện tại cho
                            {{ data.summary.computed_work_days }} công nhưng phiếu lương ghi
                            {{ data.summary.payslip_work_days }} công (chấm công đã
                            được duyệt/sửa sau khi tính lương).
                        </template>
                    </v-alert>

                    <div class="d-flex flex-wrap ga-2 mb-3">
                        <v-chip color="primary" variant="tonal">
                            Công thực tế: {{ data.summary.computed_work_days }} /
                            {{ data.summary.standard_work_days }}
                        </v-chip>
                        <v-chip color="info" variant="tonal">
                            Nghỉ phép có lương: {{ data.summary.paid_leave_days }}
                        </v-chip>
                        <v-chip color="grey" variant="tonal">
                            Nghỉ không lương: {{ data.summary.unpaid_leave_days }}
                        </v-chip>
                        <v-chip
                            v-if="data.summary.not_counted_records > 0"
                            color="warning"
                            variant="tonal"
                        >
                            {{ data.summary.not_counted_records }} bản ghi không được tính
                        </v-chip>
                    </div>

                    <v-table density="compact" class="workday-table">
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Ca</th>
                                <th>Vào – Ra</th>
                                <th class="text-right">Đi muộn / Về sớm</th>
                                <th class="text-right">OT</th>
                                <th>Trạng thái</th>
                                <th class="text-right">Công</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!data.attendances.length">
                                <td colspan="7" class="text-center text-medium-emphasis py-4">
                                    Không có bản ghi chấm công nào trong kỳ.
                                </td>
                            </tr>
                            <tr
                                v-for="row in data.attendances"
                                :key="row.attendance_id"
                                :class="{ 'row-skipped': !row.counted }"
                            >
                                <td>{{ formatDate(row.date) }}</td>
                                <td>{{ row.work_shift ?? "—" }}</td>
                                <td>{{ formatTime(row.check_in_at) }} – {{ formatTime(row.check_out_at) }}</td>
                                <td class="text-right">
                                    {{ row.late_minutes || 0 }}' / {{ row.early_leave_minutes || 0 }}'
                                </td>
                                <td class="text-right">
                                    <span v-if="row.overtime_minutes && row.overtime_approved">
                                        {{ row.overtime_minutes }}'
                                        <v-icon
                                            v-if="row.overtime_payable"
                                            size="14"
                                            color="success"
                                            title="Được tính tiền OT"
                                        >mdi-check</v-icon>
                                    </span>
                                    <span v-else>—</span>
                                </td>
                                <td>
                                    <v-chip
                                        v-if="row.counted"
                                        size="x-small"
                                        color="success"
                                        variant="tonal"
                                    >Đã tính</v-chip>
                                    <v-chip v-else size="x-small" color="warning" variant="tonal">
                                        {{ reasonLabel(row.not_counted_reason) }}
                                    </v-chip>
                                </td>
                                <td class="text-right font-weight-medium">
                                    {{ row.counted ? row.day_equivalent.toFixed(2) : "0" }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="data.attendances.length">
                            <tr>
                                <td colspan="6" class="text-right font-weight-bold">Tổng công thực tế</td>
                                <td class="text-right font-weight-bold">
                                    {{ data.summary.computed_work_days.toFixed(2) }}
                                </td>
                            </tr>
                        </tfoot>
                    </v-table>

                    <template v-if="data.leaves.length">
                        <div class="text-subtitle-2 font-weight-bold mt-4 mb-1">
                            Nghỉ phép đã duyệt trong kỳ
                        </div>
                        <v-table density="compact">
                            <tbody>
                                <tr v-for="(leave, i) in data.leaves" :key="i">
                                    <td>{{ formatDate(leave.from_date) }} – {{ formatDate(leave.to_date) }}</td>
                                    <td>{{ leave.leave_type }}</td>
                                    <td>{{ leave.is_paid ? "Có lương" : "Không lương" }}</td>
                                    <td class="text-right">{{ leave.total_days }} ngày</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </template>
                </template>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import payrollService from "../../services/payrollService";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    detail: { type: Object, default: null },
    payroll: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue"]);

const open = computed({
    get: () => props.modelValue,
    set: (value) => emit("update:modelValue", value),
});

const data = ref(null);
const loading = ref(false);
const error = ref("");

const REASONS = {
    pending: "Chưa duyệt công",
    rejected: "Công bị từ chối",
    shift_deleted: "Ca đã bị xóa",
};
const reasonLabel = (reason) => REASONS[reason] ?? "Không tính";

function formatDate(value) {
    if (!value) return "—";
    const [y, m, d] = value.slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}

function formatTime(value) {
    if (!value) return "—";
    return new Date(value).toLocaleTimeString("vi-VN", {
        hour: "2-digit",
        minute: "2-digit",
    });
}

watch(
    () => props.modelValue,
    async (isOpen) => {
        if (!isOpen || !props.detail || !props.payroll) return;
        data.value = null;
        error.value = "";
        loading.value = true;
        try {
            const response = await payrollService.workdays(props.payroll.id, props.detail.id);
            data.value = response.data;
        } catch (e) {
            error.value = e.response?.data?.message ?? "Không thể tải chi tiết ngày công.";
        } finally {
            loading.value = false;
        }
    },
);
</script>

<style scoped>
.row-skipped {
    opacity: 0.65;
    background: rgba(255, 152, 0, 0.08);
}
</style>
