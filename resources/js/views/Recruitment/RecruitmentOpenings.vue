<template>
    <div>
        <PageHeader
            title="Tuyển dụng"
            subtitle="Đợt tuyển theo chức vụ — HR tải CV, Admin duyệt, HR hẹn phỏng vấn và nhận việc."
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
                    Đợt tuyển
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
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField
                    v-model="search"
                    placeholder="Tìm tên đợt tuyển..."
                />
                <v-select
                    v-model="statusFilter"
                    :items="statusOptions"
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                />
            </div>
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="openings"
            :loading="loading"
            :actions="actions"
            no-data-text="Chưa có đợt tuyển nào."
        >
            <template #item.title="{ item }">
                <router-link
                    :to="{
                        name: 'recruitment-detail',
                        params: { id: item.id },
                    }"
                    class="font-weight-medium text-primary text-decoration-none"
                >
                    {{ item.title }}
                </router-link>
                <div class="text-caption" style="opacity: 0.7">
                    {{ item.department?.name ?? "—"
                    }}<template v-if="item.position">
                        · {{ item.position.name }}</template
                    >
                    ·
                    {{
                        CONTRACT_TYPE_LABELS[item.contract_type] ??
                        item.contract_type
                    }}
                </div>
            </template>
            <template #item.progress="{ item }">
                <div style="min-width: 160px">
                    <div class="d-flex justify-space-between text-caption mb-1">
                        <span
                            >{{ item.approved_count }}/{{ item.headcount }} CV
                            đã duyệt</span
                        >
                        <span v-if="item.pending_count" class="text-warning"
                            >{{ item.pending_count }} chờ duyệt</span
                        >
                    </div>
                    <v-progress-linear
                        :model-value="
                            (item.approved_count / item.headcount) * 100
                        "
                        :color="
                            item.approved_count >= item.headcount
                                ? 'info'
                                : 'primary'
                        "
                        rounded
                        height="6"
                    />
                </div>
            </template>
            <template #item.deadline="{ item }">
                <template v-if="item.deadline">
                    <span :class="{ 'text-error': item.is_past_deadline }">
                        {{ formatDate(item.deadline)
                        }}<template v-if="item.is_past_deadline">
                            (đã hết hạn)</template
                        >
                    </span>
                    <v-chip
                        v-if="
                            !item.is_past_deadline &&
                            item.status === 'open' &&
                            daysUntil(item.deadline) <= 3
                        "
                        class="ml-1"
                        color="warning"
                        size="x-small"
                        variant="tonal"
                    >
                        {{
                            daysUntil(item.deadline) === 0
                                ? "Hết hạn hôm nay"
                                : `Còn ${daysUntil(item.deadline)} ngày`
                        }}
                    </v-chip>
                </template>
                <span v-else style="opacity: 0.5">—</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="OPENING_STATUS_MAP" />
            </template>
        </DataTable>

        <RecruitmentOpeningForm
            v-model="formDialog"
            :opening="editing"
            :department-options="departmentOptions"
            @saved="loadData"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import recruitmentService from "../../services/recruitmentService";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useToastStore } from "../../stores/useToastStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import RecruitmentOpeningForm from "./RecruitmentOpeningForm.vue";
import { daysUntil } from "../../composables/employeeAlerts";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import {
    CONTRACT_TYPE_LABELS,
    OPENING_STATUS_MAP,
} from "../../composables/recruitmentStatus";
import { useRouteAction } from "../../composables/useRouteAction";

const router = useRouter();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();
const toast = useToastStore();

const canManage = computed(() =>
    auth.permissions.includes("recruitment.manage"),
);

const openings = ref([]);
const loading = ref(false);
const loadError = ref("");
const search = ref("");
const statusFilter = useRememberedRef("recruitment.status", null);
const formDialog = ref(false);
const editing = ref(null);

const statusOptions = [
    { title: "Tất cả trạng thái", value: null },
    ...Object.entries(OPENING_STATUS_MAP).map(([value, { label }]) => ({
        title: label,
        value,
    })),
];

const headers = [
    { title: "Đợt tuyển", key: "title" },
    { title: "Tiến độ CV", key: "progress", sortable: false },
    { title: "Hạn nộp", key: "deadline", width: 170 },
    { title: "Trạng thái", key: "status", width: 140 },
];

function flatten(nodes) {
    return nodes.flatMap((node) => [node, ...flatten(node.children ?? [])]);
}
const departmentOptions = computed(() =>
    flatten(departmentStore.tree).map((d) => ({ title: d.name, value: d.id })),
);

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "";
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await recruitmentService.listOpenings({
            per_page: 100,
            status: statusFilter.value || undefined,
            search: search.value || undefined,
        });
        openings.value = response.data.data;
    } catch (e) {
        openings.value = [];
        loadError.value =
            e.response?.data?.message ?? "Không thể tải danh sách đợt tuyển.";
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

const actions = computed(() => [
    {
        icon: "mdi-eye-outline",
        tooltip: "Xem CV & phỏng vấn",
        onClick: (item) =>
            router.push({
                name: "recruitment-detail",
                params: { id: item.id },
            }),
    },
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        hidden: () => !canManage.value,
        onClick: (item) => {
            editing.value = item;
            formDialog.value = true;
        },
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa",
        color: "error",
        hidden: (item) => !canManage.value || item.candidates_count > 0,
        confirm: {
            title: "Xóa đợt tuyển",
            message: (item) => `Xóa đợt tuyển "${item.title}"?`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            try {
                await recruitmentService.removeOpening(item.id);
                toast.success("Đã xóa đợt tuyển.");
            } catch (e) {
                toast.error(firstError(e) ?? "Không xóa được đợt tuyển.");
            }
            await loadData();
        },
    },
]);

function firstError(e) {
    const errors = e.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message;
}

// Realtime: HR/Admin khác tải CV, duyệt, hẹn phỏng vấn... -> tự tải lại, không F5.
useRealtimeRefresh(loadData, {
    shared: [
        {
            resource: "recruitment",
            permission: ["recruitment.manage", "recruitment.approve"],
        },
    ],
});

// SearchField đã tự debounce khi gõ.
watch([search, statusFilter], loadData);

// Mở thẳng thao tác khi vào trang bằng ?action=... (lệnh Ctrl+K).
useRouteAction({ create: () => canManage.value && openCreate() });

onMounted(() => {
    loadData();
    if (canManage.value) {
        departmentStore.fetchTree();
    }
});
</script>
