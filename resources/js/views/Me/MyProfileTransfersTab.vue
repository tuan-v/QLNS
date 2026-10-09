<template>
    <div>
        <v-alert
            v-if="transfersError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ transfersError }}
        </v-alert>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Loại</th>
                        <th>Ngày hiệu lực</th>
                        <th>Từ (phòng ban · chức vụ)</th>
                        <th>Đến (phòng ban · chức vụ)</th>
                        <th>Lý do</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="transfersLoading">
                        <td colspan="5" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!transfers.length">
                        <td
                            colspan="5"
                            class="text-center py-6"
                            style="opacity: 0.6"
                        >
                            Chưa có lịch sử luân chuyển nào.
                        </td>
                    </tr>
                    <tr v-for="t in transfers" v-else :key="t.id">
                        <td>
                            <StatusChip :status="t.type" :map="TRANSFER_TYPE_MAP" />
                        </td>
                        <td>{{ formatDate(t.effective_date) }}</td>
                        <td>
                            <template v-if="t.type === 'onboard'">
                                <span style="opacity: 0.5">— (mới vào làm)</span>
                            </template>
                            <template v-else>
                                <div>{{ t.from_department?.name ?? "—" }}</div>
                                <div class="text-caption" style="opacity: 0.7">
                                    {{ t.old_position?.name ?? "—" }}
                                </div>
                            </template>
                        </td>
                        <td>
                            <div class="font-weight-medium">{{ t.to_department?.name ?? "—" }}</div>
                            <div class="text-caption" style="opacity: 0.7">
                                {{ t.new_position?.name ?? "—" }}
                            </div>
                        </td>
                        <td>{{ t.reason ?? "—" }}</td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>
    </div>
</template>

<script setup>
// Tab "Luân chuyển" của MyProfile.vue — tách riêng theo yêu cầu dễ bảo trì.
// Chỉ đọc lịch sử luân chuyển của chính mình, không có thao tác nào.
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { TRANSFER_TYPE_MAP } from "../../composables/transferTypes";
import StatusChip from "../../components/common/StatusChip.vue";

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

const transfers = ref([]);
const transfersLoading = ref(false);
const transfersError = ref("");

async function loadTransfers(opts) {
    const silent = opts?.silent === true;
    if (!silent) {
        transfersLoading.value = true;
    }
    transfersError.value = "";
    try {
        const response = await employeeService.myTransfers();
        transfers.value = response.data.data;
    } catch (e) {
        transfersError.value =
            e.response?.data?.message ?? "Không thể tải lịch sử luân chuyển.";
    } finally {
        transfersLoading.value = false;
    }
}

useRealtimeRefresh(loadTransfers, { mine: ["transfers"] });

onMounted(() => {
    loadTransfers();
});
</script>
