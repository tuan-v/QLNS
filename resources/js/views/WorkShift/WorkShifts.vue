<template>
    <div>
        <PageHeader
            title="Danh sách ca làm việc"
            subtitle="Quản lý ca làm việc dùng cho chấm công."
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
                    Ca làm việc
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

        <v-sheet
            class="border rounded-lg pa-4 mb-4 glass-panel"
            color="transparent"
        >
            <SearchField v-model="search" placeholder="Tìm mã hoặc tên ca..." />
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="workShifts"
            :loading="loading"
            :search="search"
            :actions="actions"
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
            </template>
            <template #item.name="{ item }">
                <span>{{ item.name }}</span>
            </template>
            <template #item.time_range="{ item }">
                {{ item.start_time?.slice(0, 5) }} —
                {{ item.end_time?.slice(0, 5) }}
            </template>
            <template #item.standard_work_minutes="{ item }">
                {{ formatHours(item.standard_work_minutes) }}
            </template>
            <template #item.work_coefficient="{ item }">
                {{ item.work_coefficient }}
            </template>
            <template #item.is_active="{ item }">
                <StatusChip :status="item.is_active" :map="ACTIVE_STATUS_MAP" />
            </template>
        </DataTable>

        <WorkShiftFormDialog
            v-model="formDialog"
            :work-shift="editing"
            @saved="fetchData"
        />
    </div>
</template>
<script setup>
import { computed, onMounted, ref } from "vue";
import { useAuthStore } from "../../stores/authStore";
import workShiftService from "../../services/workShiftService";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import WorkShiftFormDialog from "./WorkShiftForm.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const ACTIVE_STATUS_MAP = {
    1: { label: "Hoạt động", color: "success" },
    0: { label: "Ngừng hoạt động", color: "error" },
};

const auth = useAuthStore();
const toast = useToastStore();

const search = ref("");
const workShifts = ref([]);
const loading = ref(false);
const loadError = ref("");

const formDialog = ref(false);
const editing = ref(null);

const canManage = computed(() => auth.permissions.includes("shift.manage"));

const headers = [
    { title: "#", key: "index", sortable: false, width: 56 },
    { title: "Mã", key: "code", width: 110 },
    { title: "Tên ca", key: "name" },
    { title: "Khung giờ", key: "time_range", width: 160 },
    { title: "Công chuẩn", key: "standard_work_minutes", width: 120 },
    { title: "Hệ số công", key: "work_coefficient", width: 110 },
    { title: "Trạng thái", key: "is_active", width: 150 },
];

function formatHours(minutes) {
    return (Number(minutes) / 60).toFixed(1) + " giờ";
}

async function fetchData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await workShiftService.list({ per_page: 1000 });
        workShifts.value = response.data.data;
    } catch (e) {
        workShifts.value = [];
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
            title: "Xóa ca làm việc",
            message: (item) =>
                `Bạn có chắc muốn xóa ca làm việc "${item.name}" không?`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await workShiftService.remove(item.id);
            await fetchData();
            toast.success(`Đã xóa ca làm việc "${item.name}".`);
        },
    },
]);

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(workShift) {
    editing.value = workShift;
    formDialog.value = true;
}

onMounted(() => {
    fetchData();
});
</script>
