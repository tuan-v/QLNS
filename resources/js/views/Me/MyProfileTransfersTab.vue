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
                        <th>Từ phòng ban</th>
                        <th>Đến phòng ban</th>
                        <th>Chức vụ mới</th>
                        <th>Ngày hiệu lực</th>
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
                            Chưa có lượt luân chuyển nào.
                        </td>
                    </tr>
                    <tr v-for="t in transfers" v-else :key="t.id">
                        <td>{{ t.from_department?.name ?? "—" }}</td>
                        <td>{{ t.to_department?.name ?? "—" }}</td>
                        <td>{{ t.new_position?.name ?? "—" }}</td>
                        <td>{{ formatDate(t.effective_date) }}</td>
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

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

const transfers = ref([]);
const transfersLoading = ref(false);
const transfersError = ref("");

async function loadTransfers() {
    transfersLoading.value = true;
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

onMounted(() => {
    loadTransfers();
});
</script>
