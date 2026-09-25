<template>
    <div>
        <v-alert
            v-if="shiftsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ shiftsError }}
        </v-alert>

        <!-- Danh sách thẻ trên di động thay vì bảng 5 cột (2026-09-25, theo
             yêu cầu người dùng — cùng cách đã làm ở trang Chấm công/Nghỉ phép). -->
        <div v-if="mobile" class="d-flex flex-column ga-3">
            <div v-if="shiftsLoading" class="d-flex justify-center py-6">
                <v-progress-circular indeterminate size="24" />
            </div>
            <v-sheet
                v-else-if="!shifts.length"
                class="border rounded-lg pa-5 glass-panel text-center"
                color="transparent"
                style="opacity: 0.6"
            >
                Chưa được gán ca làm việc nào.
            </v-sheet>
            <v-sheet
                v-for="a in shifts"
                v-else
                :key="a.id"
                class="border rounded-lg pa-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex justify-space-between align-start mb-2">
                    <div class="font-weight-bold">
                        {{ a.work_shift?.name ?? "—" }}
                        <span style="opacity: 0.5">({{ a.work_shift?.code }})</span>
                    </div>
                    <v-chip
                        size="small"
                        variant="tonal"
                        :color="a.status === 'active' ? 'success' : 'default'"
                    >
                        {{ a.status === "active" ? "Đang áp dụng" : "Đã kết thúc" }}
                    </v-chip>
                </div>
                <div class="text-body-2" style="opacity: 0.75">
                    {{ formatDate(a.effective_from) }} -
                    {{ a.effective_to ? formatDate(a.effective_to) : "Không thời hạn" }}
                </div>
                <div class="text-body-2" style="opacity: 0.75">
                    Ngày trong tuần: {{ formatWorkDays(a.work_days) }}
                </div>
            </v-sheet>
        </div>

        <v-sheet v-else class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Ca làm việc</th>
                        <th>Ngày bắt đầu</th>
                        <th>Ngày kết thúc</th>
                        <th>Ngày trong tuần</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="shiftsLoading">
                        <td colspan="5" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!shifts.length">
                        <td
                            colspan="5"
                            class="text-center py-6"
                            style="opacity: 0.6"
                        >
                            Chưa được gán ca làm việc nào.
                        </td>
                    </tr>
                    <tr v-for="a in shifts" v-else :key="a.id">
                        <td>
                            {{ a.work_shift?.name ?? "—" }}
                            <span style="opacity: 0.5"
                                >({{ a.work_shift?.code }})</span
                            >
                        </td>
                        <td>{{ formatDate(a.effective_from) }}</td>
                        <td>
                            {{
                                a.effective_to
                                    ? formatDate(a.effective_to)
                                    : "—"
                            }}
                        </td>
                        <td>{{ formatWorkDays(a.work_days) }}</td>
                        <td>
                            <v-chip
                                size="small"
                                variant="tonal"
                                :color="
                                    a.status === 'active'
                                        ? 'success'
                                        : 'default'
                                "
                            >
                                {{
                                    a.status === "active"
                                        ? "Đang áp dụng"
                                        : "Đã kết thúc"
                                }}
                            </v-chip>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>
    </div>
</template>

<script setup>
// Tab "Ca làm việc" của MyProfile.vue — tách riêng theo yêu cầu dễ bảo trì.
// Chỉ đọc, không có thao tác nào (gán/sửa/xóa ca là việc của HR/Quản lý —
// xem EmployeeShiftAssignmentsTab.vue ở EmployeeDetail.vue).
import { onMounted, ref } from "vue";
import { useDisplay } from "vuetify";
import employeeService from "../../services/employeeService";

const { mobile } = useDisplay();

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

const WEEK_DAY_LABEL = {
    1: "T2",
    2: "T3",
    3: "T4",
    4: "T5",
    5: "T6",
    6: "T7",
    7: "CN",
};

function formatWorkDays(days) {
    if (!days?.length) {
        return "—";
    }
    return [...days]
        .sort((a, b) => a - b)
        .map((d) => WEEK_DAY_LABEL[d] ?? d)
        .join(", ");
}

const shifts = ref([]);
const shiftsLoading = ref(false);
const shiftsError = ref("");

async function loadShifts() {
    shiftsLoading.value = true;
    shiftsError.value = "";
    try {
        const response = await employeeService.myShiftAssignments();
        shifts.value = response.data;
    } catch (e) {
        shiftsError.value =
            e.response?.data?.message ?? "Không thể tải danh sách ca làm việc.";
    } finally {
        shiftsLoading.value = false;
    }
}

onMounted(() => {
    loadShifts();
});
</script>
