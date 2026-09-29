<template>
    <div>
        <PageHeader
            title="Đơn nghỉ việc"
            subtitle="Mở từng đơn để đọc đầy đủ thông tin trước khi duyệt — duyệt xong, qua ngày làm việc cuối nhân viên tự chuyển sang “Đã nghỉ việc”."
        />

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
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField v-model="search" placeholder="Tìm theo tên, mã nhân viên..." />
                <v-select
                    v-model="status"
                    :items="RESIGNATION_STATUS_OPTIONS"
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                />
            </div>
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="items"
            :loading="loading"
            :server-items-length="total"
            :actions="actions"
            :actions-width="150"
            no-data-text="Không có đơn nghỉ việc nào."
            v-model:page="page"
            v-model:items-per-page="perPage"
        >
            <template #item.employee="{ item }">
                <div class="d-flex align-center ga-3 py-1">
                    <v-avatar
                        :color="item.employee.avatar_url ? undefined : avatarColor(item.employee.full_name)"
                        variant="tonal"
                        size="36"
                    >
                        <v-img v-if="item.employee.avatar_url" :src="item.employee.avatar_url" cover />
                        <span v-else class="text-caption font-weight-bold">{{ initials(item.employee.full_name) }}</span>
                    </v-avatar>
                    <div>
                        <div class="font-weight-medium">{{ item.employee.full_name }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ item.employee.code }} · {{ item.employee.department ?? "—" }}
                        </div>
                    </div>
                </div>
            </template>
            <template #item.last_working_date="{ item }">
                {{ formatDate(item.last_working_date) }}
            </template>
            <template #item.created_at="{ item }">
                <span class="text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="RESIGNATION_STATUS_MAP" />
            </template>
        </DataTable>

        <!-- Hộp chi tiết: đọc TOÀN BỘ thông tin rồi mới duyệt/từ chối (2026-09-29,
             theo yêu cầu người dùng) — nút duyệt chỉ nằm trong đây, không có
             nút duyệt thẳng trên dòng như đơn nghỉ phép. -->
        <v-dialog v-model="detailOpen" max-width="720" scrollable>
            <v-card rounded="xl">
                <v-card-title class="d-flex align-center pt-5 px-6">
                    <span class="text-h6 font-weight-bold">Chi tiết đơn nghỉ việc</span>
                    <v-spacer />
                    <v-btn icon variant="text" size="small" aria-label="Đóng" @click="detailOpen = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="px-6">
                    <div v-if="detailLoading" class="d-flex justify-center py-10">
                        <v-progress-circular indeterminate />
                    </div>

                    <template v-else-if="detail">
                        <!-- Nhân viên -->
                        <div class="d-flex align-center ga-4 mb-4">
                            <v-avatar
                                size="64"
                                :color="detail.employee.avatar_url ? undefined : avatarColor(detail.employee.full_name)"
                                variant="tonal"
                            >
                                <v-img v-if="detail.employee.avatar_url" :src="detail.employee.avatar_url" cover />
                                <span v-else class="text-h6 font-weight-bold">{{ initials(detail.employee.full_name) }}</span>
                            </v-avatar>
                            <div class="flex-grow-1">
                                <div class="text-h6 font-weight-bold">{{ detail.employee.full_name }}</div>
                                <div class="text-body-2 text-medium-emphasis">
                                    {{ detail.employee.position ?? "Chưa xếp chức vụ" }} — {{ detail.employee.department ?? "—" }}
                                </div>
                                <div class="d-flex flex-wrap ga-2 mt-2">
                                    <StatusChip :status="detail.employee.employment_status" :map="EMPLOYMENT_STATUS_MAP" />
                                    <v-chip size="small" variant="tonal">{{ detail.employee.code }}</v-chip>
                                </div>
                            </div>
                            <StatusChip :status="detail.status" :map="RESIGNATION_STATUS_MAP" />
                        </div>

                        <v-row dense class="mb-2">
                            <v-col v-for="info in employeeInfo" :key="info.label" cols="12" sm="6">
                                <div class="text-caption text-medium-emphasis">{{ info.label }}</div>
                                <div class="text-body-2 font-weight-medium">{{ info.value }}</div>
                            </v-col>
                        </v-row>

                        <v-divider class="my-4" />

                        <!-- Nội dung đơn -->
                        <v-row dense>
                            <v-col cols="12" sm="6">
                                <div class="text-caption text-medium-emphasis">Ngày nộp đơn</div>
                                <div class="text-body-2 font-weight-medium">{{ formatDateTime(detail.created_at) }}</div>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <div class="text-caption text-medium-emphasis">Ngày làm việc cuối cùng</div>
                                <div class="text-body-2 font-weight-medium">
                                    {{ formatDate(detail.last_working_date) }}
                                    <span class="text-medium-emphasis">({{ daysUntilText(detail.last_working_date) }})</span>
                                </div>
                            </v-col>
                            <v-col cols="12">
                                <div class="text-caption text-medium-emphasis">Lý do nghỉ việc</div>
                                <v-sheet class="border rounded-lg pa-3 mt-1 text-body-2 reason-box">{{ detail.reason }}</v-sheet>
                            </v-col>
                        </v-row>

                        <!-- Đã xử lý -->
                        <v-alert
                            v-if="detail.decided_at"
                            :type="detail.status === 'approved' ? 'success' : 'error'"
                            variant="tonal"
                            density="compact"
                            class="mt-4"
                        >
                            {{ detail.status === "approved" ? "Đã duyệt" : "Đã từ chối" }} bởi
                            <strong>{{ detail.decider?.user_name ?? "—" }}</strong> lúc {{ formatDateTime(detail.decided_at) }}.
                            <div v-if="detail.decision_note" class="mt-1">
                                {{ detail.status === "approved" ? "Ghi chú" : "Lý do" }}: {{ detail.decision_note }}
                            </div>
                            <div v-if="detail.status === 'approved'" class="mt-1">
                                {{ detail.applied_at ? "Nhân viên đã được chuyển sang “Đã nghỉ việc”." : "Nhân viên sẽ tự chuyển sang “Đã nghỉ việc” sau ngày làm việc cuối." }}
                            </div>
                        </v-alert>

                        <!-- Duyệt / từ chối -->
                        <template v-if="detail.status === 'pending' && canDecide">
                            <v-textarea
                                v-model="decisionNote"
                                label="Ghi chú / lý do (bắt buộc khi từ chối)"
                                rows="2"
                                auto-grow
                                class="mt-4"
                                :error-messages="decisionError"
                            />
                        </template>
                        <v-alert
                            v-else-if="detail.status === 'pending'"
                            type="info"
                            variant="tonal"
                            density="compact"
                            class="mt-4"
                        >
                            Bạn chỉ xem được đơn này — chỉ HR hoặc quản lý trực tiếp của nhân viên mới duyệt được.
                        </v-alert>
                    </template>
                </v-card-text>

                <v-card-actions v-if="detail?.status === 'pending' && canDecide" class="px-6 pb-5">
                    <v-spacer />
                    <v-btn
                        color="error"
                        variant="outlined"
                        :loading="deciding === 'rejected'"
                        :disabled="deciding !== null"
                        @click="submitDecision('rejected')"
                    >
                        Từ chối
                    </v-btn>
                    <v-btn
                        color="primary"
                        :loading="deciding === 'approved'"
                        :disabled="deciding !== null"
                        @click="submitDecision('approved')"
                    >
                        Duyệt đơn
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import resignationService from "../../services/resignationService";
import { useResourceSyncStore } from "../../stores/useResourceSyncStore";
import { useToastStore } from "../../stores/useToastStore";
import PageHeader from "../../components/common/PageHeader.vue";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { avatarColor, initials } from "../../composables/avatar";
import { EMPLOYMENT_STATUS_MAP } from "../../composables/employmentStatus";
import { RESIGNATION_STATUS_MAP, RESIGNATION_STATUS_OPTIONS } from "../../composables/resignationStatus";

const route = useRoute();
const toast = useToastStore();
const resourceSync = useResourceSyncStore();

const CONTRACT_TYPE_LABELS = { thu_viec: "Thử việc", chinh_thuc: "Chính thức" };

const headers = [
    { title: "Nhân viên", key: "employee", sortable: false },
    { title: "Ngày làm việc cuối", key: "last_working_date", sortable: false, width: 170 },
    { title: "Ngày nộp", key: "created_at", sortable: false, width: 130 },
    { title: "Trạng thái", key: "status", sortable: false, width: 140 },
];

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const loadError = ref("");
const page = ref(1);
const perPage = ref(10);
const search = ref("");
// Mặc định mở ra thấy ngay các đơn CẦN XỬ LÝ.
const status = ref("pending");

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await resignationService.list({
            page: page.value,
            per_page: perPage.value,
            status: status.value || undefined,
            search: search.value || undefined,
        });
        items.value = response.data.data;
        total.value = response.data.meta?.total ?? items.value.length;
    } catch (e) {
        items.value = [];
        loadError.value = e.response?.data?.message ?? "Không thể tải danh sách đơn nghỉ việc.";
    } finally {
        loading.value = false;
    }
}

watch([search, status, perPage], () => {
    page.value = 1;
    loadData();
});
watch(page, loadData);

const actions = [
    {
        icon: "mdi-file-eye-outline",
        label: "Xem & duyệt",
        tooltip: "Mở đơn để đọc đầy đủ thông tin",
        onClick: (item) => openDetail(item.id),
    },
];

/* ------------------------------ Hộp chi tiết ------------------------------ */

const detailOpen = ref(false);
const detailLoading = ref(false);
const detail = ref(null);
const canDecide = ref(false);
const decisionNote = ref("");
const decisionError = ref("");
const deciding = ref(null);

async function openDetail(id) {
    detailOpen.value = true;
    detailLoading.value = true;
    detail.value = null;
    decisionNote.value = "";
    decisionError.value = "";
    try {
        const response = await resignationService.show(id);
        detail.value = response.data.data;
        canDecide.value = Boolean(response.data.can_decide);
    } catch (e) {
        detailOpen.value = false;
        toast.error(e.response?.data?.message ?? "Không thể mở đơn nghỉ việc này.");
    } finally {
        detailLoading.value = false;
    }
}

const employeeInfo = computed(() => {
    const e = detail.value?.employee;
    if (!e) {
        return [];
    }
    return [
        { label: "Ngày vào làm", value: formatDate(e.hire_date) },
        { label: "Thâm niên", value: formatTenure(e.hire_date) },
        { label: "Quản lý trực tiếp", value: e.manager ?? "Không có" },
        { label: "Email công ty", value: e.company_email ?? "—" },
        {
            label: "Hợp đồng hiện tại",
            value: e.contract
                ? `${e.contract.contract_number} — ${CONTRACT_TYPE_LABELS[e.contract.contract_type] ?? e.contract.contract_type}`
                : "Không có hợp đồng hiệu lực",
        },
        {
            label: "Hợp đồng đến ngày",
            value: e.contract ? (e.contract.end_date ? formatDate(e.contract.end_date) : "Không thời hạn") : "—",
        },
    ];
});

async function submitDecision(decision) {
    decisionError.value = "";
    if (decision === "rejected" && !decisionNote.value.trim()) {
        decisionError.value = "Vui lòng nhập lý do từ chối.";
        return;
    }
    deciding.value = decision;
    try {
        const response = await resignationService.decide(detail.value.id, {
            status: decision,
            note: decisionNote.value.trim() || null,
        });
        detail.value = { ...detail.value, ...response.data.data, employee: detail.value.employee };
        toast.success(decision === "approved" ? "Đã duyệt đơn nghỉ việc." : "Đã từ chối đơn nghỉ việc.");
        loadData();
    } catch (e) {
        decisionError.value =
            e.response?.data?.errors?.note?.[0] ??
            e.response?.data?.errors?.status?.[0] ??
            e.response?.data?.message ??
            "Không thể xử lý đơn.";
    } finally {
        deciding.value = null;
    }
}

/* -------------------------------- Tiện ích -------------------------------- */

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "—";
}

function formatDateTime(value) {
    return value
        ? new Date(value).toLocaleString("vi-VN", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" })
        : "—";
}

function daysUntilText(dateIso) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(`${dateIso}T00:00:00`);
    const days = Math.round((target - today) / 86400000);
    if (days > 0) {
        return `còn ${days} ngày`;
    }
    return days === 0 ? "hôm nay" : `đã qua ${-days} ngày`;
}

function formatTenure(hireDate) {
    if (!hireDate) {
        return "—";
    }
    const start = new Date(hireDate);
    const now = new Date();
    let months = (now.getFullYear() - start.getFullYear()) * 12 + (now.getMonth() - start.getMonth());
    if (now.getDate() < start.getDate()) {
        months -= 1;
    }
    if (months < 0) {
        return "—";
    }
    const years = Math.floor(months / 12);
    const rest = months % 12;
    if (years === 0) {
        return `${rest} tháng`;
    }
    return rest === 0 ? `${years} năm` : `${years} năm ${rest} tháng`;
}

// Phiên KHÁC vừa nộp/duyệt đơn -> tự tải lại (ResourceChanged 'resignations').
watch(() => resourceSync.signals.resignations, loadData);

// Đang đứng sẵn ở trang này mà bấm thêm 1 thông báo đơn khác -> component
// không mount lại, phải tự bắt ?id= mới.
watch(
    () => route.query.id,
    (id) => {
        if (id) {
            openDetail(Number(id));
        }
    },
);

onMounted(() => {
    loadData();
    resourceSync.connect("resignations");
    // Bấm thông báo "Đơn xin nghỉ việc mới" ở chuông -> mở thẳng đúng đơn đó.
    if (route.query.id) {
        openDetail(Number(route.query.id));
    }
});

onUnmounted(() => {
    resourceSync.disconnect("resignations");
});
</script>

<style scoped>
.reason-box {
    white-space: pre-wrap;
    background: rgb(var(--v-theme-muted));
}
</style>
