<template>
    <div>
        <PageHeader
            title="Onboarding / Offboarding"
            subtitle="Theo dõi các việc cần làm khi nhân viên nhận việc và nghỉ việc."
        >
            <template #actions>
                <template v-if="canManage">
                    <v-btn variant="tonal" size="large" prepend-icon="mdi-clipboard-list-outline" @click="templatesOpen = true">
                        Mẫu checklist
                    </v-btn>
                    <v-btn color="primary" variant="flat" size="large" prepend-icon="mdi-plus" @click="createOpen = true">
                        Tạo checklist
                    </v-btn>
                </template>
            </template>
        </PageHeader>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>

        <v-tabs v-model="type" color="primary" class="mb-4">
            <v-tab value="onboarding" prepend-icon="mdi-account-plus-outline">Nhận việc</v-tab>
            <v-tab value="offboarding" prepend-icon="mdi-account-arrow-right-outline">Nghỉ việc</v-tab>
        </v-tabs>

        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField v-model="search" placeholder="Tìm theo tên, mã nhân viên..." />
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
            :items="checklists"
            :loading="loading"
            :actions="actions"
            :no-data-text="type === 'onboarding' ? 'Chưa có checklist nhận việc nào.' : 'Chưa có checklist nghỉ việc nào.'"
        >
            <template #item.employee="{ item }">
                <div class="d-flex align-center ga-3 py-2" style="cursor: pointer" @click="openChecklist(item)">
                    <v-avatar size="36" color="primary" variant="tonal">
                        <v-img v-if="item.employee?.avatar_url" :src="item.employee.avatar_url" cover />
                        <span v-else class="text-caption">{{ initials(item.employee?.full_name) }}</span>
                    </v-avatar>
                    <div>
                        <div class="font-weight-medium text-primary">{{ item.employee?.full_name }}</div>
                        <div class="text-caption" style="opacity: 0.7">
                            {{ item.employee?.code }}<template v-if="item.employee?.department"> · {{ item.employee.department }}</template>
                        </div>
                    </div>
                </div>
            </template>
            <template #item.progress="{ item }">
                <div style="min-width: 170px">
                    <div class="d-flex justify-space-between text-caption mb-1">
                        <span>{{ item.progress.done }}/{{ item.progress.total }} việc</span>
                        <strong>{{ item.progress.percent }}%</strong>
                    </div>
                    <v-progress-linear
                        :model-value="item.progress.percent"
                        :color="item.status === 'completed' ? 'success' : 'primary'"
                        rounded
                        height="6"
                    />
                </div>
            </template>
            <template #item.reference_date="{ item }">
                {{ formatDate(item.reference_date) }}
            </template>
            <template #item.due="{ item }">
                <v-chip v-if="item.status === 'in_progress' && item.overdue_count" color="error" size="small" variant="tonal">
                    {{ item.overdue_count }} việc quá hạn
                </v-chip>
                <span v-else-if="item.status === 'in_progress' && item.next_due_date" class="text-body-2">
                    {{ formatDate(item.next_due_date) }}
                </span>
                <span v-else style="opacity: 0.5">—</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="CHECKLIST_STATUS_MAP" />
            </template>
        </DataTable>

        <ChecklistDrawer
            ref="drawerRef"
            v-model="drawerOpen"
            :checklist-id="selectedId"
            @changed="loadData"
        />
        <ChecklistCreateDialog v-model="createOpen" :default-type="type" @created="onCreated" />
        <ChecklistTemplatesDialog v-model="templatesOpen" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import onboardingService from "../../services/onboardingService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import ChecklistDrawer from "./ChecklistDrawer.vue";
import ChecklistCreateDialog from "./ChecklistCreateDialog.vue";
import ChecklistTemplatesDialog from "./ChecklistTemplatesDialog.vue";
import { initials } from "../../composables/avatar";
import { CHECKLIST_STATUS_MAP, REFERENCE_DATE_LABELS, formatDate } from "../../composables/onboardingLabels";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { useRouteAction } from "../../composables/useRouteAction";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToastStore();

const canManage = computed(() => auth.permissions.includes("onboarding.manage"));

const type = ref(route.query.type === "offboarding" ? "offboarding" : "onboarding");
const search = ref("");
// Không nhớ bộ lọc trạng thái: mặc định luôn là việc còn dang dở.
const statusFilter = ref("in_progress");
const checklists = ref([]);
const loading = ref(false);
const loadError = ref("");
const drawerOpen = ref(false);
const drawerRef = ref(null);
const selectedId = ref(null);
const createOpen = ref(false);
const templatesOpen = ref(false);

const statusOptions = [
    { title: "Tất cả trạng thái", value: null },
    ...Object.entries(CHECKLIST_STATUS_MAP).map(([value, { label }]) => ({ title: label, value })),
];

const headers = computed(() => [
    { title: "Nhân viên", key: "employee", sortable: false },
    { title: "Tiến độ", key: "progress", sortable: false },
    { title: REFERENCE_DATE_LABELS[type.value], key: "reference_date", width: 160 },
    { title: "Hạn gần nhất", key: "due", sortable: false, width: 160 },
    { title: "Trạng thái", key: "status", width: 140 },
]);

const actions = [
    { icon: "mdi-eye-outline", tooltip: "Xem checklist", onClick: (item) => openChecklist(item) },
];

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await onboardingService.list({
            type: type.value,
            status: statusFilter.value || undefined,
            search: search.value || undefined,
            per_page: 100,
        });
        checklists.value = response.data.data;
    } catch (e) {
        checklists.value = [];
        loadError.value = e.response?.data?.message ?? "Không tải được danh sách checklist.";
    } finally {
        loading.value = false;
    }
}

function openChecklist(item) {
    selectedId.value = item.id;
    drawerOpen.value = true;
}

function onCreated(checklist) {
    toast.success("Đã tạo checklist.");
    if (checklist.type !== type.value) {
        type.value = checklist.type;
    } else {
        loadData();
    }
    openChecklist(checklist);
}

// Mở thẳng 1 checklist từ thông báo (?id=...).
function openFromQuery() {
    const id = Number(route.query.id);
    if (!id) return;
    openChecklist({ id });
    router.replace({ query: { ...route.query, id: undefined } });
}

useRealtimeRefresh(
    async () => {
        await loadData();
        if (drawerOpen.value) {
            await drawerRef.value?.reload();
        }
    },
    { shared: [{ resource: "onboarding", permission: ["onboarding.manage", "onboarding.team"] }] },
);

useRouteAction({ create: () => canManage.value && (createOpen.value = true) });

watch([type, search, statusFilter], loadData);
watch(() => route.query.id, openFromQuery);

onMounted(() => {
    loadData();
    openFromQuery();
});
</script>
