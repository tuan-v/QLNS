<template>
    <div>
        <PageHeader
            :title="opening?.title ?? 'Đợt tuyển dụng'"
            :subtitle="subtitle"
        >
            <template #actions>
                <v-btn
                    variant="tonal"
                    prepend-icon="mdi-arrow-left"
                    :to="{ name: 'recruitment' }"
                    >Quay lại</v-btn
                >
                <template v-if="opening && canManage">
                    <v-btn
                        v-if="opening.status !== 'closed'"
                        variant="tonal"
                        color="error"
                        prepend-icon="mdi-lock-outline"
                        :loading="toggling"
                        @click="toggleClosed"
                    >
                        Đóng đợt tuyển
                    </v-btn>
                    <v-btn
                        v-else
                        variant="tonal"
                        color="success"
                        prepend-icon="mdi-lock-open-outline"
                        :loading="toggling"
                        @click="toggleClosed"
                    >
                        Mở lại
                    </v-btn>
                    <v-btn
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-file-multiple-outline"
                        :disabled="!acceptingCv"
                        @click="bulkUploadDialog = true"
                    >
                        Tải nhiều CV
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-upload-outline"
                        :disabled="!acceptingCv"
                        @click="uploadDialog = true"
                    >
                        Tải CV
                    </v-btn>
                </template>
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

        <template v-if="opening">
            <v-sheet
                class="border rounded-lg pa-4 mb-4 glass-panel"
                color="transparent"
            >
                <div class="d-flex flex-wrap align-center ga-4">
                    <StatusChip
                        :status="opening.status"
                        :map="OPENING_STATUS_MAP"
                    />
                    <div style="min-width: 260px; flex: 1">
                        <div
                            class="d-flex justify-space-between text-body-2 mb-1"
                        >
                            <span
                                ><strong
                                    >{{ opening.approved_count }}/{{
                                        opening.headcount
                                    }}</strong
                                >
                                CV đã duyệt</span
                            >
                            <span
                                v-if="opening.pending_count"
                                class="text-warning"
                                >{{ opening.pending_count }} CV chờ duyệt</span
                            >
                        </div>
                        <v-progress-linear
                            :model-value="
                                (opening.approved_count / opening.headcount) *
                                100
                            "
                            :color="
                                opening.approved_count >= opening.headcount
                                    ? 'info'
                                    : 'primary'
                            "
                            rounded
                            height="8"
                        />
                    </div>
                    <div class="text-body-2">
                        Hạn nộp:
                        <strong
                            :class="{ 'text-error': opening.is_past_deadline }"
                        >
                            {{
                                opening.deadline
                                    ? formatDate(opening.deadline)
                                    : "Không giới hạn"
                            }}
                        </strong>
                    </div>
                </div>
                <v-alert
                    v-if="!acceptingCv"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                >
                    {{ notAcceptingReason }}
                </v-alert>
                <div
                    v-if="opening.description"
                    class="text-body-2 mt-3"
                    style="white-space: pre-line; opacity: 0.8"
                >
                    {{ opening.description }}
                </div>
            </v-sheet>

            <DataTable
                :headers="headers"
                :items="opening.candidates"
                :loading="loading"
                :actions="actions"
                actions-width="260"
                no-data-text="Chưa có CV nào."
                :selectable="canApprove || canManage"
                :item-selectable="itemSelectableForBulk"
                :bulk-actions="bulkActions"
                @action-error="onActionError"
                @bulk-action-error="onActionError"
            >
                <template #item.full_name="{ item }">
                    <div class="font-weight-medium">{{ item.full_name }}</div>
                    <div class="text-caption" style="opacity: 0.7">
                        {{ item.email
                        }}<template v-if="item.phone">
                            · {{ item.phone }}</template
                        >
                    </div>
                    <div
                        v-if="item.submitter"
                        class="text-caption"
                        style="opacity: 0.5"
                    >
                        HR gửi: {{ item.submitter }}
                    </div>
                </template>
                <template #item.status="{ item }">
                    <StatusChip
                        :status="item.status"
                        :map="CANDIDATE_STATUS_MAP"
                    />
                    <v-chip
                        v-if="
                            item.status === 'pending' &&
                            pendingDays(item) >= PENDING_WARN_DAYS
                        "
                        class="ml-1"
                        color="error"
                        size="x-small"
                        variant="tonal"
                        prepend-icon="mdi-timer-alert-outline"
                    >
                        Chờ duyệt {{ pendingDays(item) }} ngày
                    </v-chip>
                    <div
                        v-if="item.status === 'rejected' && item.review_note"
                        class="text-caption mt-1"
                        style="opacity: 0.7"
                    >
                        {{ item.review_note }}
                    </div>
                    <div v-if="item.hired_employee" class="text-caption mt-1">
                        <router-link
                            :to="{
                                name: 'employee-detail',
                                params: { id: item.hired_employee.id },
                            }"
                        >
                            {{ item.hired_employee.code }}
                        </router-link>
                    </div>
                    <!-- Thư mời nhận việc gần nhất (RecruitmentOfferService). -->
                    <div v-if="item.offer && item.status !== 'hired'" class="mt-1">
                        <StatusChip :status="item.offer.status" :map="OFFER_STATUS_MAP" size="x-small" />
                        <div class="text-caption" style="opacity: 0.7">
                            {{ formatMoney(item.offer.salary) }} · bắt đầu {{ formatDate(item.offer.start_date) }}
                            <template v-if="item.offer.status === 'sent'"> · hạn trả lời {{ formatDate(item.offer.response_deadline) }}</template>
                        </div>
                        <div v-if="item.offer.status === 'rejected' && item.offer.review_note" class="text-caption text-error">
                            Admin: {{ item.offer.review_note }}
                        </div>
                        <div v-if="item.offer.status === 'declined' && item.offer.response_note" class="text-caption" style="opacity: 0.7">
                            Ứng viên: {{ item.offer.response_note }}
                        </div>
                    </div>
                </template>
                <template #item.interview="{ item }">
                    <template v-if="item.interviews?.length">
                        <div>
                            {{
                                formatDateTime(item.interviews[0].scheduled_at)
                            }}
                        </div>
                        <div
                            class="text-caption"
                            style="opacity: 0.7; word-break: break-word"
                        >
                            {{
                                item.interviews[0].format === "online"
                                    ? "Trực tuyến"
                                    : "Trực tiếp"
                            }}
                            ·
                            <a
                                v-if="
                                    item.interviews[0].format === 'online' &&
                                    /^https?:\/\//i.test(
                                        item.interviews[0].location,
                                    )
                                "
                                :href="item.interviews[0].location"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ item.interviews[0].location }}</a
                            >
                            <template v-else>{{
                                item.interviews[0].location
                            }}</template>
                        </div>
                        <div
                            v-if="item.interviews[0].result_note"
                            class="text-caption"
                            style="opacity: 0.7"
                        >
                            {{ item.interviews[0].result_note }}
                        </div>
                    </template>
                    <span v-else style="opacity: 0.5">—</span>
                </template>
            </DataTable>
        </template>

        <CandidateUploadDialog
            v-model="uploadDialog"
            :opening="opening"
            @saved="loadData"
        />
        <BulkCandidateUploadDialog
            v-model="bulkUploadDialog"
            :opening="opening"
            @saved="loadData"
        />
        <InterviewScheduleDialog
            v-model="bulkScheduleDialog"
            :bulk-candidates="bulkScheduleTargets"
            @saved="loadData"
        />
        <InterviewScheduleDialog
            v-model="scheduleDialog"
            :candidate="selected"
            @saved="loadData"
        />
        <InterviewResultDialog
            v-model="resultDialog"
            :candidate="selected"
            @saved="loadData"
        />
        <FilePreviewDialog
            v-model="previewDialog"
            :file-url="previewFile.url"
            :file-name="previewFile.name"
        />
        <OfferDialog
            v-model="offerDialog"
            :candidate="selected"
            :default-contract-type="opening?.contract_type"
            @saved="onOfferSaved"
        />
        <EmployeeFormDialog
            v-model="hireDialog"
            :employee="null"
            :department-options="departmentOptions"
            :prefill="hirePrefill"
            @saved="onHired"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import recruitmentService from "../../services/recruitmentService";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useToastStore } from "../../stores/useToastStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import FilePreviewDialog from "../../components/common/FilePreviewDialog.vue";
import EmployeeFormDialog from "../Employee/EmployeeForm.vue";
import CandidateUploadDialog from "./CandidateUploadDialog.vue";
import InterviewScheduleDialog from "./InterviewScheduleDialog.vue";
import InterviewResultDialog from "./InterviewResultDialog.vue";
import BulkCandidateUploadDialog from "./BulkCandidateUploadDialog.vue";
import OfferDialog from "./OfferDialog.vue";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import {
    CANDIDATE_STATUS_MAP,
    CONTRACT_TYPE_LABELS,
    OFFER_STATUS_MAP,
    OPENING_STATUS_MAP,
} from "../../composables/recruitmentStatus";

const route = useRoute();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();
const toast = useToastStore();

const canManage = computed(() =>
    auth.permissions.includes("recruitment.manage"),
);
const canApprove = computed(() =>
    auth.permissions.includes("recruitment.approve"),
);

const opening = ref(null);
const loading = ref(false);
const loadError = ref("");
const toggling = ref(false);
const selected = ref(null);
const uploadDialog = ref(false);
const scheduleDialog = ref(false);
const resultDialog = ref(false);
const previewDialog = ref(false);
const previewFile = ref({ url: "", name: "" });
const hireDialog = ref(false);
const hirePrefill = ref(null);
const offerDialog = ref(false);

// Offer "đang mở" — mỗi ứng viên tối đa 1 (khớp RecruitmentOffer::OPEN_STATUSES).
const OPEN_OFFER_STATUSES = ["pending_approval", "sent", "accepted"];
const hasOpenOffer = (item) => OPEN_OFFER_STATUSES.includes(item.offer?.status);

function formatMoney(value) {
    return Number(value ?? 0).toLocaleString("vi-VN") + " ₫";
}

function onOfferSaved() {
    toast.success("Đã gửi offer cho Admin duyệt.");
    loadData();
}

const headers = [
    { title: "Ứng viên", key: "full_name" },
    { title: "Trạng thái", key: "status", width: 170 },
    { title: "Phỏng vấn", key: "interview", sortable: false },
];

const subtitle = computed(() => {
    const o = opening.value;
    if (!o) return "";
    return [
        o.department?.name,
        o.position?.name,
        CONTRACT_TYPE_LABELS[o.contract_type],
    ]
        .filter(Boolean)
        .join(" · ");
});

const isFull = computed(
    () =>
        opening.value &&
        opening.value.approved_count >= opening.value.headcount,
);
const acceptingCv = computed(
    () =>
        opening.value &&
        opening.value.status === "open" &&
        !isFull.value &&
        !opening.value.is_past_deadline,
);
const notAcceptingReason = computed(() => {
    const o = opening.value;
    if (!o) return "";
    if (o.status === "closed") return "Đợt tuyển đã đóng — không nhận thêm CV.";
    if (isFull.value)
        return `Đã đủ ${o.headcount} CV được duyệt — ngưng nhận CV mới. CV không đạt phỏng vấn sẽ trả lại suất.`;
    if (o.is_past_deadline) return "Đã quá hạn nộp CV.";
    return "";
});

function flatten(nodes) {
    return nodes.flatMap((node) => [node, ...flatten(node.children ?? [])]);
}
const departmentOptions = computed(() =>
    flatten(departmentStore.tree).map((d) => ({ title: d.name, value: d.id })),
);

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN") : "";
}
function formatDateTime(value) {
    return value
        ? new Date(value).toLocaleString("vi-VN", {
              hour: "2-digit",
              minute: "2-digit",
              day: "2-digit",
              month: "2-digit",
              year: "numeric",
          })
        : "";
}
function firstError(e) {
    const errors = e?.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e?.response?.data?.message;
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await recruitmentService.showOpening(route.params.id);
        opening.value = response.data.data;
    } catch (e) {
        loadError.value =
            e.response?.data?.message ?? "Không thể tải đợt tuyển.";
    } finally {
        loading.value = false;
    }
}

async function toggleClosed() {
    toggling.value = true;
    try {
        const closing = opening.value.status !== "closed";
        const response = closing
            ? await recruitmentService.closeOpening(opening.value.id)
            : await recruitmentService.reopenOpening(opening.value.id);
        opening.value = response.data.data;
        toast.success(closing ? "Đã đóng đợt tuyển." : "Đã mở lại đợt tuyển.");
    } catch (e) {
        toast.error(firstError(e) ?? "Không thực hiện được.");
    } finally {
        toggling.value = false;
    }
}

function onActionError({ error }) {
    toast.error(firstError(error) ?? "Không thực hiện được thao tác.");
    loadData();
}

function interviewIsDue(item) {
    const i = item.interviews?.find((x) => x.status === "scheduled");
    return i ? new Date(i.scheduled_at) <= new Date() : false;
}

// Tải qua axios (kèm token đăng nhập — link trần sẽ bị 401), giữ đúng tên file gốc.
// Lỗi thì DataTable phát action-error -> onActionError hiện thông báo.
async function downloadCv(item) {
    const response = await window.axios.get(item.cv_url, {
        responseType: "blob",
    });
    const url = window.URL.createObjectURL(
        new Blob([response.data], { type: "application/pdf" }),
    );
    const link = document.createElement("a");
    link.href = url;
    link.download = item.cv_original_name || `cv-${item.id}.pdf`;
    link.click();
    window.URL.revokeObjectURL(url);
}

/* ---------------------------- Thao tác hàng loạt ---------------------------- */
const bulkUploadDialog = ref(false);
const bulkScheduleDialog = ref(false);
const bulkScheduleTargets = ref([]);

// Chỉ cho chọn dòng còn thao tác hàng loạt được: CV chờ duyệt (Admin) hoặc đã duyệt/đã hẹn (HR hẹn PV).
function itemSelectableForBulk(item) {
    return (
        (canApprove.value && item.status === "pending") ||
        (canManage.value &&
            ["approved", "interview_scheduled"].includes(item.status))
    );
}

function bulkResultMessage(result, verb) {
    if (result.done) toast.success(`Đã ${verb} ${result.done} CV.`);
    if (result.failed.length) {
        toast.error(
            `Không ${verb} được ${result.failed.length} CV: ` +
                result.failed
                    .map((f) => `${f.full_name} — ${f.message}`)
                    .join("; "),
        );
    }
}

const bulkActions = computed(() =>
    [
        {
            icon: "mdi-check-all",
            label: "Duyệt",
            color: "success",
            hidden: () => !canApprove.value,
            disabled: (items) => !items.some((i) => i.status === "pending"),
            confirm: {
                title: "Duyệt nhiều CV",
                message: (items) =>
                    `Duyệt ${items.filter((i) => i.status === "pending").length} CV đã chọn? Duyệt lần lượt theo thứ tự trong bảng; đủ ${opening.value.headcount} suất thì CV dư báo lỗi.`,
                confirmText: "Duyệt",
            },
            onClick: async (items) => {
                const { data } = await recruitmentService.bulkReview({
                    candidate_ids: items
                        .filter((i) => i.status === "pending")
                        .map((i) => i.id),
                    status: "approved",
                });
                bulkResultMessage(data, "duyệt");
                await loadData();
            },
        },
        {
            icon: "mdi-close-box-multiple-outline",
            label: "Từ chối",
            color: "error",
            hidden: () => !canApprove.value,
            disabled: (items) => !items.some((i) => i.status === "pending"),
            confirm: {
                title: "Từ chối nhiều CV",
                message: (items) =>
                    `Từ chối ${items.filter((i) => i.status === "pending").length} CV đã chọn? Từng ứng viên sẽ nhận email báo kết quả.`,
                confirmText: "Từ chối",
                input: { label: "Lý do (chỉ nội bộ thấy)", required: true },
            },
            onClick: async (items, { input }) => {
                const { data } = await recruitmentService.bulkReview({
                    candidate_ids: items
                        .filter((i) => i.status === "pending")
                        .map((i) => i.id),
                    status: "rejected",
                    review_note: input,
                });
                bulkResultMessage(data, "từ chối");
                await loadData();
            },
        },
        {
            icon: "mdi-calendar-multiple",
            label: "Hẹn phỏng vấn",
            hidden: () => !canManage.value,
            disabled: (items) =>
                !items.some((i) =>
                    ["approved", "interview_scheduled"].includes(i.status),
                ),
            onClick: (items) => {
                bulkScheduleTargets.value = items.filter((i) =>
                    ["approved", "interview_scheduled"].includes(i.status),
                );
                bulkScheduleDialog.value = true;
            },
        },
        // Thanh hàng loạt của DataTable không tự đọc `hidden` — lọc theo quyền tại đây.
    ].filter((action) => !action.hidden?.()),
);

// CV chờ duyệt quá lâu -> gắn chip đỏ để Admin thấy ngay.
const PENDING_WARN_DAYS = 3;
function pendingDays(item) {
    return item.created_at
        ? Math.floor(
              (Date.now() - new Date(item.created_at).getTime()) / 86400000,
          )
        : 0;
}

function onHired() {
    toast.success("Ứng viên đã được nhận việc.");
    loadData();
}

const actions = computed(() => [
    {
        icon: "mdi-file-eye-outline",
        tooltip: "Xem CV",
        onClick: (item) => {
            previewFile.value = {
                url: item.cv_url,
                name: item.cv_original_name || "cv.pdf",
            };
            previewDialog.value = true;
        },
    },
    {
        icon: "mdi-download-outline",
        tooltip: "Tải CV xuống",
        onClick: downloadCv,
    },
    {
        icon: "mdi-check",
        tooltip: "Duyệt CV",
        color: "success",
        hidden: (item) => !canApprove.value || item.status !== "pending",
        disabled: () => isFull.value || opening.value?.status === "closed",
        confirm: {
            title: "Duyệt CV",
            message: (item) =>
                `Duyệt CV của ${item.full_name}? CV đã duyệt chiếm 1 suất trong ${opening.value.headcount} suất của đợt tuyển.`,
            confirmText: "Duyệt",
        },
        onClick: async (item) => {
            await recruitmentService.reviewCandidate(item.id, {
                status: "approved",
            });
            toast.success("Đã duyệt CV.");
            await loadData();
        },
    },
    {
        icon: "mdi-close",
        tooltip: "Từ chối CV",
        color: "error",
        hidden: (item) => !canApprove.value || item.status !== "pending",
        confirm: {
            title: "Từ chối CV",
            message: (item) =>
                `Từ chối CV của ${item.full_name}? Ứng viên sẽ nhận email thông báo kết quả.`,
            confirmText: "Từ chối",
            input: { label: "Lý do (chỉ nội bộ thấy)", required: true },
        },
        onClick: async (item, { input }) => {
            await recruitmentService.reviewCandidate(item.id, {
                status: "rejected",
                review_note: input,
            });
            toast.success("Đã từ chối CV.");
            await loadData();
        },
    },
    {
        icon: "mdi-calendar-account-outline",
        tooltip: (item) =>
            item.status === "interview_scheduled"
                ? "Dời lịch phỏng vấn"
                : "Hẹn phỏng vấn",
        hidden: (item) =>
            !canManage.value ||
            !["approved", "interview_scheduled"].includes(item.status),
        onClick: (item) => {
            selected.value = item;
            scheduleDialog.value = true;
        },
    },
    {
        icon: "mdi-clipboard-check-outline",
        tooltip: (item) =>
            interviewIsDue(item)
                ? "Ghi kết quả phỏng vấn"
                : "Chưa tới giờ phỏng vấn",
        color: "success",
        hidden: (item) =>
            !canManage.value || item.status !== "interview_scheduled",
        disabled: (item) => !interviewIsDue(item),
        onClick: (item) => {
            selected.value = item;
            resultDialog.value = true;
        },
    },
    {
        icon: "mdi-email-plus-outline",
        tooltip: "Soạn thư mời nhận việc (offer)",
        color: "primary",
        hidden: (item) => !canManage.value || item.status !== "passed" || hasOpenOffer(item),
        onClick: (item) => {
            selected.value = item;
            offerDialog.value = true;
        },
    },
    {
        icon: "mdi-email-check-outline",
        tooltip: "Duyệt offer & gửi ứng viên",
        color: "success",
        hidden: (item) => !canApprove.value || item.offer?.status !== "pending_approval",
        confirm: {
            title: "Duyệt thư mời nhận việc",
            message: (item) =>
                `Gửi offer cho ${item.full_name}: ${CONTRACT_TYPE_LABELS[item.offer.contract_type] ?? item.offer.contract_type}, ${formatMoney(item.offer.salary)}/tháng, bắt đầu ${formatDate(item.offer.start_date)}, hạn trả lời ${formatDate(item.offer.response_deadline)}? Ứng viên sẽ nhận email có link trả lời.`,
            confirmText: "Duyệt & gửi",
        },
        onClick: async (item) => {
            try {
                await recruitmentService.reviewOffer(item.offer.id, { status: "approved" });
                toast.success("Đã duyệt và gửi offer cho ứng viên.");
            } catch (e) {
                toast.error(firstError(e) ?? "Không duyệt được offer.");
            }
            await loadData();
        },
    },
    {
        icon: "mdi-email-remove-outline",
        tooltip: "Không duyệt offer",
        color: "error",
        hidden: (item) => !canApprove.value || item.offer?.status !== "pending_approval",
        confirm: {
            title: "Không duyệt offer",
            message: (item) => `Không duyệt offer cho ${item.full_name}? HR sẽ được báo để soạn lại.`,
            confirmText: "Không duyệt",
            input: { label: "Lý do (HR sẽ thấy)", required: true },
        },
        onClick: async (item, { input }) => {
            try {
                await recruitmentService.reviewOffer(item.offer.id, { status: "rejected", review_note: input });
                toast.success("Đã trả offer về cho HR.");
            } catch (e) {
                toast.error(firstError(e) ?? "Không cập nhật được offer.");
            }
            await loadData();
        },
    },
    {
        icon: "mdi-email-off-outline",
        tooltip: "Rút offer",
        color: "error",
        hidden: (item) => !canManage.value || !["pending_approval", "sent"].includes(item.offer?.status),
        confirm: {
            title: "Rút thư mời nhận việc",
            message: (item) =>
                item.offer.status === "sent"
                    ? `Rút offer đã gửi cho ${item.full_name}? Link trong email sẽ không dùng được nữa.`
                    : `Rút offer đang chờ duyệt của ${item.full_name}?`,
            confirmText: "Rút offer",
        },
        onClick: async (item) => {
            try {
                await recruitmentService.withdrawOffer(item.offer.id);
                toast.success("Đã rút offer.");
            } catch (e) {
                toast.error(firstError(e) ?? "Không rút được offer.");
            }
            await loadData();
        },
    },
    {
        icon: "mdi-account-plus-outline",
        tooltip: "Nhận việc (tạo hồ sơ nhân viên)",
        color: "teal",
        // Chỉ khi ứng viên đã chấp nhận offer (RecruitmentService::markHired kiểm tra lại).
        hidden: (item) => !canManage.value || item.status !== "passed" || item.offer?.status !== "accepted",
        onClick: (item) => {
            hirePrefill.value = {
                candidate_id: item.id,
                full_name: item.full_name,
                personal_email: item.email,
                phone: item.phone,
                department_id: opening.value.department_id,
                position_id: opening.value.position_id,
                contract_type: item.offer.contract_type,
                agreed_salary: item.offer.salary,
                hire_date: item.offer.start_date,
            };
            hireDialog.value = true;
        },
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa CV",
        color: "error",
        hidden: (item) =>
            !canManage.value || !["pending", "rejected"].includes(item.status),
        confirm: {
            title: "Xóa CV",
            message: (item) => `Xóa CV của ${item.full_name}?`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await recruitmentService.removeCandidate(item.id);
            toast.success("Đã xóa CV.");
            await loadData();
        },
    },
]);

// Realtime: HR/Admin khác tải CV, duyệt, hẹn phỏng vấn... -> tự tải lại, không F5.
useRealtimeRefresh(loadData, {
    shared: [
        {
            resource: "recruitment",
            permission: ["recruitment.manage", "recruitment.approve"],
        },
    ],
});

// Bấm thông báo của đợt tuyển khác khi đang ở trang này: cùng component, chỉ đổi :id.
watch(
    () => route.params.id,
    (id, oldId) => {
        if (id && id !== oldId) {
            opening.value = null;
            loadData();
        }
    },
);

onMounted(() => {
    loadData();
    if (canManage.value) {
        departmentStore.fetchTree();
    }
});
</script>
