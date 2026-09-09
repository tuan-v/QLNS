<template>
    <div>
        <PageHeader
            title="Danh sách điểm chấm công"
            subtitle="Quản lý điểm chấm công qua Wifi/GPS/QR."
        >
            <template #actions>
                <v-btn
                    v-if="canManage"
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="openCreate"
                >
                    Điểm chấm công
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <SearchField v-model="search" placeholder="Tìm mã hoặc tên điểm..." />
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="locations"
            :loading="loading"
            :search="search"
            :actions="actions"
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
            </template>
            <template #item.method="{ item }">
                <v-chip size="small" variant="tonal" :color="METHOD_COLOR[item.method]">
                    {{ METHOD_LABEL[item.method] }}
                </v-chip>
            </template>
            <template #item.detail="{ item }">
                <span v-if="item.method === 'wifi'">{{ item.wifi_ssid }}</span>
                <span v-else-if="item.method === 'gps'">
                    {{ item.latitude }}, {{ item.longitude }} (±{{ item.radius_meters }}m)
                </span>
                <span v-else style="opacity: 0.6">Mã QR</span>
            </template>
            <template #item.is_active="{ item }">
                <StatusChip :status="item.is_active" :map="ACTIVE_STATUS_MAP" />
            </template>
        </DataTable>

        <AttendanceLocationFormDialog
            v-model="formDialog"
            :location="editing"
            @saved="fetchData"
        />
    </div>
</template>
<script setup>
import { computed, onMounted, ref } from "vue";
import { useAuthStore } from "../../stores/authStore";
import attendanceLocationService from "../../services/attendanceLocationService";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import AttendanceLocationFormDialog from "./AttendanceLocationForm.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const ACTIVE_STATUS_MAP = {
    1: { label: "Hoạt động", color: "success" },
    0: { label: "Ngừng hoạt động", color: "default" },
};

const METHOD_LABEL = { wifi: "Wifi", gps: "GPS", qr: "Mã QR" };
const METHOD_COLOR = { wifi: "info", gps: "success", qr: "purple" };

const auth = useAuthStore();
const toast = useToastStore();

const search = ref("");
const locations = ref([]);
const loading = ref(false);
const loadError = ref("");

const formDialog = ref(false);
const editing = ref(null);

const canManage = computed(() => auth.permissions.includes("location.manage"));

const headers = [
    { title: "#", key: "index", sortable: false, width: 56 },
    { title: "Mã", key: "code", width: 110 },
    { title: "Tên điểm", key: "name" },
    { title: "Phương thức", key: "method", width: 130 },
    { title: "Chi tiết", key: "detail" },
    { title: "Trạng thái", key: "is_active", width: 150 },
];

async function fetchData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await attendanceLocationService.list({ per_page: 1000 });
        locations.value = response.data.data;
    } catch (e) {
        locations.value = [];
        loadError.value =
            e.response?.data?.message ??
            "Không thể kết nối máy chủ, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

const actions = computed(() => [
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        hidden: !canManage.value,
        onClick: openEdit,
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa",
        color: "error",
        hidden: !canManage.value,
        confirm: {
            title: "Xóa điểm chấm công",
            message: (item) =>
                `Bạn có chắc muốn xóa điểm chấm công "${item.name}" không?`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await attendanceLocationService.remove(item.id);
            await fetchData();
            toast.success(`Đã xóa điểm chấm công "${item.name}".`);
        },
    },
]);

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(location) {
    editing.value = location;
    formDialog.value = true;
}

onMounted(() => {
    fetchData();
});
</script>
