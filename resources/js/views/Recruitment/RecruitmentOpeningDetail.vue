<template>
    <div>
        <PageHeader :title="opening?.title ?? 'Đợt tuyển dụng'" :subtitle="subtitle">
            <template #actions>
                <v-btn variant="tonal" prepend-icon="mdi-arrow-left" :to="{ name: 'recruitment' }">Quay lại</v-btn>
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
            <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
                <div class="d-flex flex-wrap align-center ga-4">
                    <StatusChip :status="opening.status" :map="OPENING_STATUS_MAP" />
                    <div style="min-width: 260px; flex: 1">
                        <div class="d-flex justify-space-between text-body-2 mb-1">
                            <span v-if="opening.headcount"
                                ><strong>{{ opening.approved_count }}/{{ opening.headcount }}</strong> CV đã duyệt</span
                            >
                            <span v-else
                                ><strong>{{ opening.approved_count }}</strong> CV đã duyệt · không giới hạn số lượng</span
                            >
                            <span v-if="opening.pending_count" class="text-warning"
                                >{{ opening.pending_count }} CV chờ duyệt</span
                            >
                        </div>
                        <v-progress-linear
                            v-if="opening.headcount"
                            :model-value="(opening.approved_count / opening.headcount) * 100"
                            :color="opening.approved_count >= opening.headcount ? 'info' : 'primary'"
                            rounded
                            height="8"
                        />
                    </div>
                    <div class="text-body-2">
                        Hạn nộp:
                        <strong :class="{ 'text-error': opening.is_past_deadline }">
                            {{ opening.deadline ? formatDate(opening.deadline) : "Không giới hạn" }}
                        </strong>
                    </div>
                    <!-- Bộ câu hỏi phỏng vấn (nhiều bộ, có tỉ trọng): Admin chọn; HR chỉ xem. -->
                    <div class="d-flex flex-wrap align-center ga-2">
                        <v-icon icon="mdi-clipboard-text-outline" size="20" />
                        <span class="text-body-2">Bộ câu hỏi:</span>
                        <template v-if="opening.question_sets?.length">
                            <v-chip v-for="set in opening.question_sets" :key="set.id" size="small" variant="tonal" color="primary">
                                {{ set.name }}<template v-if="opening.question_sets.length > 1"> · {{ set.weight }}%</template>
                            </v-chip>
                            <span class="text-caption" style="opacity: 0.7">đạt từ {{ openingPassPercent }}%</span>
                        </template>
                        <strong v-else class="text-body-2">{{
                            isGeneral ? "Theo vị trí ứng tuyển của từng ứng viên" : "Chưa gắn — ghi kết quả tay"
                        }}</strong>
                        <v-btn v-if="canApprove" size="small" variant="text" prepend-icon="mdi-pencil-outline" @click="setsDialog = true">
                            Chọn bộ câu hỏi
                        </v-btn>
                    </div>
                </div>
                <v-alert v-if="!acceptingCv" type="info" variant="tonal" density="compact" class="mt-3">
                    {{ notAcceptingReason }}
                </v-alert>
                <div v-if="opening.description" class="text-body-2 mt-3" style="white-space: pre-line; opacity: 0.8">
                    {{ opening.description }}
                </div>
            </v-sheet>

            <!-- Đợt tuyển chung: lọc ứng viên theo vị trí ứng tuyển -->
            <div v-if="isGeneral && positionFilterItems.length" class="d-flex flex-wrap align-center ga-2 mb-3">
                <span class="text-body-2" style="opacity: 0.7">Vị trí ứng tuyển:</span>
                <v-chip-group v-model="positionFilter" selected-class="text-primary" column>
                    <v-chip v-for="p in positionFilterItems" :key="p.value" :value="p.value" size="small" variant="outlined" filter>
                        {{ p.title }} ({{ p.count }})
                    </v-chip>
                </v-chip-group>
            </div>

            <DataTable
                :headers="headers"
                :items="filteredCandidates"
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
                        {{ item.email }}<template v-if="item.phone"> · {{ item.phone }}</template>
                    </div>
                    <div v-if="item.submitter" class="text-caption" style="opacity: 0.5">
                        HR gửi: {{ item.submitter }}
                    </div>
                    <div v-if="item.source" class="text-caption" style="opacity: 0.6">
                        <v-icon icon="mdi-source-branch" size="13" />
                        {{ CANDIDATE_SOURCE_LABELS[item.source] ?? item.source }}<template v-if="item.source_note"> · {{ item.source_note }}</template>
                    </div>
                    <!-- Đợt tuyển chung: vị trí ứng tuyển + hình thức mong muốn -->
                    <div v-if="isGeneral" class="d-flex flex-wrap ga-1 mt-1">
                        <v-chip size="x-small" variant="tonal" color="primary" prepend-icon="mdi-briefcase-outline">
                            {{ item.applied_position?.name ?? "Chưa chọn vị trí" }}
                        </v-chip>
                        <v-chip v-if="item.desired_contract_type" size="x-small" variant="tonal">
                            {{ CONTRACT_TYPE_LABELS[item.desired_contract_type] ?? item.desired_contract_type }}
                        </v-chip>
                    </div>
                    <!-- Bộ câu hỏi dùng khi chấm ứng viên này -->
                    <div v-if="item.question_sets?.length && (isGeneral || item.question_set_source === 'candidate')" class="text-caption mt-1" style="opacity: 0.7">
                        <v-icon icon="mdi-clipboard-text-outline" size="14" />
                        {{ item.question_sets.map((s) => (item.question_sets.length > 1 ? `${s.name} ${s.weight}%` : s.name)).join(" + ") }}
                        ({{ QUESTION_SET_SOURCE_LABELS[item.question_set_source] }})
                    </div>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.stage?.key" :map="CANDIDATE_STAGE_MAP" />
                    <div v-if="item.stage?.reason" class="text-caption mt-1" style="opacity: 0.75">{{ item.stage.reason }}</div>
                    <v-chip
                        v-if="item.status === 'pending' && pendingDays(item) >= PENDING_WARN_DAYS"
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
                    <!-- Từng nộp CV vào công ty (mọi đợt tuyển) — bấm để xem lịch sử. -->
                    <v-chip
                        v-if="item.previous_applications_count"
                        class="mt-1"
                        size="x-small"
                        color="info"
                        variant="tonal"
                        prepend-icon="mdi-history"
                        @click="openHistory(item)"
                    >
                        Đã nộp {{ item.previous_applications_count }} lần trước
                    </v-chip>
                    <!-- Thư mời nhận việc gần nhất (RecruitmentOfferService). -->
                    <div v-if="item.offer && item.status !== 'hired'" class="mt-1">
                        <div class="text-caption">
                            Offer: <strong>{{ OFFER_STATUS_MAP[item.offer.status]?.label ?? item.offer.status }}</strong>
                        </div>
                        <div class="text-caption" style="opacity: 0.7">
                            <template v-if="item.offer.department">{{ item.offer.position ?? item.offer.department }} · </template>
                            {{ formatMoney(item.offer.salary) }} · bắt đầu {{ formatDate(item.offer.start_date) }}
                            <template v-if="item.offer.status === 'sent'">
                                · hạn trả lời {{ formatDate(item.offer.response_deadline) }}</template
                            >
                        </div>
                        <div
                            v-if="item.offer.status === 'rejected' && item.offer.review_note"
                            class="text-caption text-error"
                        >
                            Admin: {{ item.offer.review_note }}
                        </div>
                        <div v-if="item.offer.status === 'sent'" class="text-caption text-info">
                            Chờ ứng viên liên hệ {{ item.offer.contact_name ?? "HR" }}<template v-if="item.offer.contact_phone"> ({{ item.offer.contact_phone }})</template>
                        </div>
                        <div
                            v-if="['accepted', 'declined'].includes(item.offer.status) && (item.offer.response_note || item.offer.response_recorded_by)"
                            class="text-caption"
                            style="opacity: 0.7"
                        >
                            <template v-if="item.offer.response_note">{{ item.offer.status === "declined" ? "Lý do" : "Ghi chú" }}: {{ item.offer.response_note }}</template>
                            <template v-if="item.offer.response_recorded_by"> · HR ghi nhận: {{ item.offer.response_recorded_by }}</template>
                        </div>
                    </div>
                </template>
                <template #item.interview="{ item }">
                    <template v-if="item.interviews?.length">
                        <div>
                            {{ formatDateTime(item.interviews[0].scheduled_at) }}
                        </div>
                        <!-- Phản hồi của ứng viên (HR ghi nhận ở trang Lịch phỏng vấn). -->
                        <StatusChip
                            v-if="item.interviews[0].status !== 'completed'"
                            :status="item.interviews[0].confirmation_status"
                            :map="INTERVIEW_CONFIRMATION_MAP"
                        />
                        <div class="text-caption" style="opacity: 0.7; word-break: break-word">
                            {{ item.interviews[0].format === "online" ? "Trực tuyến" : "Trực tiếp" }}
                            ·
                            <a
                                v-if="
                                    item.interviews[0].format === 'online' &&
                                    /^https?:\/\//i.test(item.interviews[0].location)
                                "
                                :href="item.interviews[0].location"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ item.interviews[0].location }}</a
                            >
                            <template v-else>{{ item.interviews[0].location }}</template>
                        </div>
                        <div v-if="item.interviews[0].result_note" class="text-caption" style="opacity: 0.7">
                            {{ item.interviews[0].result_note }}
                        </div>
                    </template>
                    <span v-else style="opacity: 0.5">—</span>
                </template>
            </DataTable>
        </template>

        <CandidateUploadDialog v-model="uploadDialog" :opening="opening" @saved="loadData" />
        <BulkCandidateUploadDialog v-model="bulkUploadDialog" :opening="opening" @saved="loadData" />
        <InterviewScheduleDialog
            v-model="bulkScheduleDialog"
            :bulk-candidates="bulkScheduleTargets"
            @saved="loadData"
        />
        <InterviewScheduleDialog v-model="scheduleDialog" :candidate="selected" @saved="loadData" />
        <InterviewResultDialog v-model="resultDialog" :candidate="selected" @saved="loadData" />
        <FilePreviewDialog v-model="previewDialog" :file-url="previewFile.url" :file-name="previewFile.name" />
        <QuestionSetWeightsDialog
            v-if="opening"
            v-model="setsDialog"
            title="Bộ câu hỏi phỏng vấn của đợt tuyển"
            subtitle="Ghép nhiều bộ (vd Văn hóa chung + Chuyên môn), mỗi bộ một tỉ trọng — phiếu chấm chia theo từng phần. Phiếu đã mở trước đó không đổi."
            empty-hint="lưu trống thì ghi kết quả phỏng vấn bằng tay"
            :sets="questionSets"
            :value="opening.question_sets ?? []"
            :saving="assigningSet"
            :error="setsError"
            :can-create="canApprove"
            @save="saveSets"
            @set-created="loadQuestionSets"
        >
            <template v-if="opening.position_id" #extra="{ apply }">
                <v-btn variant="text" prepend-icon="mdi-briefcase-outline" @click="fillFromPosition(apply)">Lấy theo chức vụ</v-btn>
            </template>
        </QuestionSetWeightsDialog>
        <!-- Bộ câu hỏi riêng của 1 ứng viên (Admin) — lưu trống = dùng mặc định -->
        <QuestionSetWeightsDialog
            v-if="setsCandidate"
            v-model="candidateSetsDialog"
            :title="`Bộ câu hỏi riêng — ${setsCandidate.full_name}`"
            subtitle="Chỉ dùng cho ứng viên này. Lưu trống = dùng mặc định (theo vị trí ứng tuyển hoặc của đợt tuyển). Không đổi được sau khi đã mở phiếu chấm."
            :empty-hint="setsCandidate.question_set_source && setsCandidate.question_set_source !== 'candidate' ? `đang dùng ${QUESTION_SET_SOURCE_LABELS[setsCandidate.question_set_source]}` : 'chưa có bộ câu hỏi nào'"
            :sets="questionSets"
            :value="setsCandidate.question_set_source === 'candidate' ? setsCandidate.question_sets : []"
            :saving="assigningSet"
            :error="setsError"
            :can-create="canApprove"
            @save="saveCandidateSets"
            @set-created="loadQuestionSets"
        >
            <template #extra="{ apply }">
                <v-btn variant="text" prepend-icon="mdi-restore" @click="apply([])">Dùng mặc định</v-btn>
            </template>
        </QuestionSetWeightsDialog>
        <v-dialog v-model="applicationDialog" max-width="460">
            <v-card v-if="applicationCandidate" rounded="xl">
                <v-card-title class="pt-5 px-6">Thông tin ứng tuyển — {{ applicationCandidate.full_name }}</v-card-title>
                <v-card-text class="px-6 d-flex flex-column ga-3">
                    <v-select
                        v-model="applicationForm.source"
                        :items="CANDIDATE_SOURCE_OPTIONS"
                        label="Nguồn ứng viên"
                        clearable
                        :error-messages="applicationErrors.source"
                    />
                    <v-text-field
                        v-model="applicationForm.source_note"
                        label="Ghi chú nguồn (vd tên người giới thiệu)"
                        :error-messages="applicationErrors.source_note"
                    />
                    <v-autocomplete
                        v-if="isGeneral"
                        v-model="applicationForm.applied_position_id"
                        :items="positionItems"
                        label="Vị trí ứng tuyển"
                        clearable
                        :error-messages="applicationErrors.applied_position_id"
                    />
                    <v-select
                        v-if="isGeneral"
                        v-model="applicationForm.desired_contract_type"
                        :items="DESIRED_CONTRACT_ITEMS"
                        label="Hình thức mong muốn"
                        clearable
                        :error-messages="applicationErrors.desired_contract_type"
                    />
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="applicationDialog = false">Hủy</v-btn>
                    <v-btn color="primary" variant="flat" :loading="savingApplication" @click="saveApplication">Lưu</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <OfferReviewDialog
            v-model="offerReviewOpen"
            :candidate="offerReviewCandidate"
            :opening="opening"
            :department-options="departmentOptions"
            :can-approve="canApprove"
            :can-manage="canManage"
            @changed="loadData"
        />
        <!-- HR ghi nhận ứng viên chấp nhận / từ chối offer (báo qua điện thoại, email) -->
        <v-dialog v-model="responseDialog" max-width="480">
            <v-card v-if="responseCandidate" rounded="xl">
                <v-card-title class="pt-5 px-6">Phản hồi offer — {{ responseCandidate.full_name }}</v-card-title>
                <v-card-text class="px-6">
                    <div class="text-body-2 mb-3" style="opacity: 0.75">
                        Ghi nhận theo những gì ứng viên báo qua điện thoại / email. Admin sẽ nhận thông báo.
                    </div>
                    <v-radio-group v-model="responseForm.decision" hide-details class="mb-3">
                        <v-radio value="accept" label="Ứng viên CHẤP NHẬN — làm thủ tục Nhận việc" color="success" />
                        <v-radio value="decline" label="Ứng viên TỪ CHỐI — trả lại suất cho đợt tuyển" color="error" />
                    </v-radio-group>
                    <v-textarea
                        v-model="responseForm.note"
                        :label="responseForm.decision === 'decline' ? 'Lý do từ chối (nếu ứng viên có nói)' : 'Ghi chú (vd: gọi điện lúc 10h, xác nhận đi làm đúng ngày)'"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        :error-messages="responseErrors.note"
                    />
                    <v-alert v-if="responseErrors.decision" type="error" variant="tonal" density="compact">{{ responseErrors.decision }}</v-alert>
                </v-card-text>
                <v-card-actions class="px-6 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="responseDialog = false">Hủy</v-btn>
                    <v-btn
                        :color="responseForm.decision === 'decline' ? 'error' : 'success'"
                        variant="flat"
                        :disabled="!responseForm.decision"
                        :loading="savingResponse"
                        @click="saveOfferResponse"
                    >
                        Ghi nhận
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
        <CandidateHistoryDialog
            v-model="historyOpen"
            :title="historyCandidate ? `Lịch sử ứng tuyển — ${historyCandidate.full_name}` : undefined"
            :preset-email="historyCandidate?.email ?? ''"
            :preset-phone="historyCandidate?.phone ?? ''"
            :exclude-id="historyCandidate?.id ?? null"
        />
        <OfferDialog
            v-model="offerDialog"
            :candidate="selected"
            :opening="opening"
            :department-options="departmentOptions"
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
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
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
import OfferReviewDialog from "./OfferReviewDialog.vue";
import CandidateHistoryDialog from "./CandidateHistoryDialog.vue";
import QuestionSetWeightsDialog from "./QuestionSetWeightsDialog.vue";
import { useRecruitmentPositions } from "../../composables/useRecruitmentPositions";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import {
    CANDIDATE_SOURCE_LABELS,
    CANDIDATE_SOURCE_OPTIONS,
    CANDIDATE_STAGE_MAP,
    CONTRACT_TYPE_LABELS,
    INTERVIEW_CONFIRMATION_MAP,
    OFFER_STATUS_MAP,
    OPENING_CONTRACT_TYPE_LABELS,
    OPENING_STATUS_MAP,
} from "../../composables/recruitmentStatus";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();
const toast = useToastStore();

const canManage = computed(() => auth.permissions.includes("recruitment.manage"));
const canApprove = computed(() => auth.permissions.includes("recruitment.approve"));

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
const historyOpen = ref(false);
const historyCandidate = ref(null);

function openHistory(item) {
    historyCandidate.value = item;
    historyOpen.value = true;
}

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
    return [o.department?.name, o.position?.name, OPENING_CONTRACT_TYPE_LABELS[o.contract_type]]
        .filter(Boolean)
        .join(" · ");
});

// headcount null = không giới hạn (đợt tuyển chung).
const isFull = computed(
    () => !!opening.value?.headcount && opening.value.approved_count >= opening.value.headcount,
);
const isGeneral = computed(() => !!opening.value && !opening.value.department_id);

const QUESTION_SET_SOURCE_LABELS = { candidate: "riêng ứng viên", position: "theo vị trí", opening: "của đợt tuyển" };
const DESIRED_CONTRACT_ITEMS = Object.entries(CONTRACT_TYPE_LABELS).map(([value, title]) => ({ title, value }));
const { positionItems } = useRecruitmentPositions();

/* ---- Lọc theo vị trí ứng tuyển (đợt chung) ---- */
const positionFilter = ref(null);
const positionFilterItems = computed(() => {
    const groups = new Map();
    for (const c of opening.value?.candidates ?? []) {
        const key = c.applied_position?.id ?? 0;
        const g = groups.get(key) ?? { value: key, title: c.applied_position?.name ?? "Chưa chọn vị trí", count: 0 };
        g.count++;
        groups.set(key, g);
    }
    return [...groups.values()].sort((a, b) => b.count - a.count);
});
const filteredCandidates = computed(() => {
    const list = opening.value?.candidates ?? [];
    if (!isGeneral.value || positionFilter.value === null || positionFilter.value === undefined) return list;
    return list.filter((c) => (c.applied_position?.id ?? 0) === positionFilter.value);
});

/* ---- Xem / duyệt offer (1 hộp thoại) ---- */
const offerReviewOpen = ref(false);
const offerReviewId = ref(null);
// Theo id -> sau khi tải lại (sửa offer) dialog hiện ngay nội dung mới.
const offerReviewCandidate = computed(() => opening.value?.candidates?.find((c) => c.id === offerReviewId.value) ?? null);

function openOfferReview(item) {
    offerReviewId.value = item.id;
    offerReviewOpen.value = true;
}

// Mở từ thông báo "Offer cần duyệt": ?offer=<id ứng viên>.
watch(
    () => [route.query.offer, opening.value?.id],
    ([candidateId]) => {
        if (!candidateId || !opening.value) return;
        const item = opening.value.candidates?.find((c) => String(c.id) === String(candidateId));
        const { offer: _offer, ...rest } = route.query;
        router.replace({ query: rest });
        if (item?.offer) openOfferReview(item);
    },
);

/* ---- HR ghi nhận phản hồi offer ---- */
const responseDialog = ref(false);
const responseCandidate = ref(null);
const responseForm = ref({ decision: null, note: "" });
const responseErrors = ref({});
const savingResponse = ref(false);

function openOfferResponse(item) {
    responseCandidate.value = item;
    responseForm.value = { decision: null, note: "" };
    responseErrors.value = {};
    responseDialog.value = true;
}

async function saveOfferResponse() {
    savingResponse.value = true;
    responseErrors.value = {};
    try {
        await recruitmentService.recordOfferResponse(responseCandidate.value.offer.id, {
            decision: responseForm.value.decision,
            note: responseForm.value.note || null,
        });
        responseDialog.value = false;
        toast.success(
            responseForm.value.decision === "accept"
                ? "Đã ghi nhận ứng viên chấp nhận offer — Admin đã được thông báo."
                : "Đã ghi nhận ứng viên từ chối offer — Admin đã được thông báo.",
        );
        await loadData();
    } catch (e) {
        const errs = e.response?.data?.errors;
        if (errs) responseErrors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]));
        else toast.error(firstError(e) ?? "Không ghi nhận được.");
    } finally {
        savingResponse.value = false;
    }
}

/* ---- Sửa vị trí ứng tuyển (đợt chung) ---- */
const applicationDialog = ref(false);
const applicationCandidate = ref(null);
const applicationForm = ref({ applied_position_id: null, desired_contract_type: null });
const applicationErrors = ref({});
const savingApplication = ref(false);

function openApplication(item) {
    applicationCandidate.value = item;
    applicationForm.value = {
        source: item.source,
        source_note: item.source_note ?? "",
        ...(isGeneral.value ? { applied_position_id: item.applied_position_id, desired_contract_type: item.desired_contract_type } : {}),
    };
    applicationErrors.value = {};
    applicationDialog.value = true;
}

async function saveApplication() {
    savingApplication.value = true;
    applicationErrors.value = {};
    try {
        await recruitmentService.updateApplication(applicationCandidate.value.id, applicationForm.value);
        applicationDialog.value = false;
        toast.success("Đã cập nhật thông tin ứng tuyển.");
        await loadData();
    } catch (e) {
        if (e.response?.status === 422) applicationErrors.value = e.response.data.errors ?? {};
        toast.error(firstError(e) ?? "Không lưu được.");
    } finally {
        savingApplication.value = false;
    }
}

/* ---- Bộ câu hỏi riêng của ứng viên (Admin) ---- */
const candidateSetsDialog = ref(false);
const setsCandidate = ref(null);

function openCandidateSets(item) {
    setsCandidate.value = item;
    setsError.value = "";
    candidateSetsDialog.value = true;
}

async function saveCandidateSets(rows) {
    assigningSet.value = true;
    setsError.value = "";
    try {
        await recruitmentService.assignCandidateQuestionSets(setsCandidate.value.id, rows);
        candidateSetsDialog.value = false;
        toast.success(rows.length ? "Đã gắn bộ câu hỏi riêng cho ứng viên." : "Ứng viên dùng lại bộ câu hỏi mặc định.");
        await loadData();
    } catch (e) {
        setsError.value = firstError(e) ?? "Không lưu được bộ câu hỏi.";
    } finally {
        assigningSet.value = false;
    }
}
const acceptingCv = computed(
    () => opening.value && opening.value.status === "open" && !isFull.value && !opening.value.is_past_deadline,
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
const departmentOptions = computed(() => flatten(departmentStore.tree).map((d) => ({ title: d.name, value: d.id })));

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

// Bộ câu hỏi phỏng vấn (Admin gắn cho đợt tuyển — InterviewScorecardService).
const questionSets = ref([]);
const setsDialog = ref(false);
const assigningSet = ref(false);
const setsError = ref("");
// % đạt của phiếu = trung bình % đạt các bộ theo tỉ trọng (khớp InterviewScorecardService).
const openingPassPercent = computed(() => {
    const sets = opening.value?.question_sets ?? [];
    return Math.round(sets.reduce((sum, s) => sum + s.pass_percent * s.weight, 0)) / 100;
});

async function loadQuestionSets() {
    try {
        questionSets.value = (await recruitmentService.questionSets()).data.data;
    } catch {
        questionSets.value = [];
    }
}

async function saveSets(rows) {
    assigningSet.value = true;
    setsError.value = "";
    try {
        await recruitmentService.assignQuestionSets(opening.value.id, rows);
        setsDialog.value = false;
        toast.success(
            rows.length
                ? "Đã lưu bộ câu hỏi — kết quả phỏng vấn sẽ chấm theo phiếu."
                : "Đã bỏ bộ câu hỏi — ghi kết quả tay như trước.",
        );
        await loadData();
    } catch (e) {
        setsError.value = firstError(e) ?? "Không lưu được bộ câu hỏi.";
    } finally {
        assigningSet.value = false;
    }
}

// Điền lại theo bộ mặc định của chức vụ (trang "Bộ câu hỏi phỏng vấn").
async function fillFromPosition(apply) {
    try {
        const positions = (await recruitmentService.positionQuestionSets()).data.data;
        const sets = positions.find((p) => p.id === opening.value.position_id)?.question_sets ?? [];
        if (!sets.length) {
            toast.info("Chức vụ này chưa có bộ câu hỏi mặc định.");
            return;
        }
        apply(sets.map((s) => ({ id: s.id, weight: s.weight })));
    } catch (e) {
        toast.error(firstError(e) ?? "Không tải được bộ câu hỏi của chức vụ.");
    }
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await recruitmentService.showOpening(route.params.id);
        opening.value = response.data.data;
        scheduleStageRefresh();
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải đợt tuyển.";
    } finally {
        loading.value = false;
    }
}

// "Chờ phỏng vấn" tự thành "Đang phỏng vấn" khi tới giờ hẹn (backend tính theo giờ lúc đọc) —
// hẹn tải lại đúng lúc buổi PV đã xác nhận gần nhất bắt đầu, không cần F5.
let stageTimer = null;
function scheduleStageRefresh() {
    clearTimeout(stageTimer);
    const now = Date.now();
    const next = (opening.value?.candidates ?? [])
        .filter((c) => c.stage?.key === "awaiting_interview")
        .flatMap((c) => c.interviews ?? [])
        .filter((i) => i.status === "scheduled" && i.confirmation_status === "confirmed")
        .map((i) => new Date(i.scheduled_at).getTime())
        .filter((t) => t > now)
        .sort((a, b) => a - b)[0];
    // setTimeout tối đa ~24 ngày; buổi xa hơn thì lần tải sau sẽ hẹn lại.
    if (next && next - now < 2 ** 31 - 1) {
        stageTimer = setTimeout(loadData, next - now + 2000);
    }
}
onBeforeUnmount(() => clearTimeout(stageTimer));

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

// Lý do chưa ghi được kết quả (null = ghi được) — khớp RecruitmentService::recordResult():
// HR phải xác nhận ứng viên ĐỒNG Ý tham gia (trang Lịch phỏng vấn) và đã tới giờ phỏng vấn.
const CONFIRMATION_BLOCK_REASONS = {
    pending: "Ứng viên chưa xác nhận tham gia — ghi nhận phản hồi ở trang Lịch phỏng vấn trước",
    reschedule_requested: "Ứng viên xin đổi lịch — hẹn lại lịch phỏng vấn trước",
    withdrawn: "Ứng viên báo không tham gia phỏng vấn",
};
function resultBlocker(item) {
    const i = item.interviews?.find((x) => x.status === "scheduled");
    if (!i) return "Chưa có lịch phỏng vấn";
    if (i.confirmation_status !== "confirmed") {
        return CONFIRMATION_BLOCK_REASONS[i.confirmation_status] ?? "Ứng viên chưa xác nhận tham gia phỏng vấn";
    }
    return interviewIsDue(item) ? null : "Chưa tới giờ phỏng vấn";
}

// Tải qua axios (kèm token đăng nhập — link trần sẽ bị 401), giữ đúng tên file gốc.
// Lỗi thì DataTable phát action-error -> onActionError hiện thông báo.
async function downloadCv(item) {
    const response = await window.axios.get(item.cv_url, {
        responseType: "blob",
    });
    const url = window.URL.createObjectURL(new Blob([response.data], { type: "application/pdf" }));
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

// Chỉ cho chọn dòng còn thao tác hàng loạt được: CV chờ duyệt (Admin) hoặc đã duyệt, CHƯA có lịch
// (HR hẹn PV). Đã có lịch thì dời lịch từng người.
function itemSelectableForBulk(item) {
    return (canApprove.value && item.status === "pending") || (canManage.value && item.status === "approved");
}

function bulkResultMessage(result, verb) {
    if (result.done) toast.success(`Đã ${verb} ${result.done} CV.`);
    if (result.failed.length) {
        toast.error(
            `Không ${verb} được ${result.failed.length} CV: ` +
                result.failed.map((f) => `${f.full_name} — ${f.message}`).join("; "),
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
                    `Duyệt ${items.filter((i) => i.status === "pending").length} CV đã chọn? Duyệt lần lượt theo thứ tự trong bảng; ${opening.value.headcount ? `đủ ${opening.value.headcount} suất thì CV dư báo lỗi.` : "đợt tuyển không giới hạn số lượng."}`,
                confirmText: "Duyệt",
            },
            onClick: async (items) => {
                const { data } = await recruitmentService.bulkReview({
                    candidate_ids: items.filter((i) => i.status === "pending").map((i) => i.id),
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
                    candidate_ids: items.filter((i) => i.status === "pending").map((i) => i.id),
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
            disabled: (items) => !items.some((i) => i.status === "approved"),
            onClick: (items) => {
                bulkScheduleTargets.value = items.filter((i) => i.status === "approved");
                bulkScheduleDialog.value = true;
            },
        },
        // Thanh hàng loạt của DataTable không tự đọc `hidden` — lọc theo quyền tại đây.
    ].filter((action) => !action.hidden?.()),
);

// CV chờ duyệt quá lâu -> gắn chip đỏ để Admin thấy ngay.
const PENDING_WARN_DAYS = 3;
function pendingDays(item) {
    return item.created_at ? Math.floor((Date.now() - new Date(item.created_at).getTime()) / 86400000) : 0;
}

function onHired() {
    toast.success("Ứng viên đã được nhận việc.");
    loadData();
}

const actions = computed(() => [
    {
        icon: "mdi-briefcase-edit-outline",
        tooltip: "Sửa thông tin ứng tuyển (nguồn, vị trí)",
        color: "primary",
        // Đã có offer đang mở / đã tuyển: thông tin đã chốt trong offer -> không sửa (backend cũng chặn).
        hidden: (item) => !canManage.value || item.status === "hired" || hasOpenOffer(item),
        onClick: openApplication,
    },
    {
        icon: "mdi-clipboard-list-outline",
        tooltip: "Bộ câu hỏi riêng cho ứng viên",
        color: "primary",
        hidden: (item) => !canApprove.value || ["rejected", "hired", "passed", "failed"].includes(item.status),
        onClick: openCandidateSets,
    },
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
                opening.value.headcount
                    ? `Duyệt CV của ${item.full_name}? CV đã duyệt chiếm 1 suất trong ${opening.value.headcount} suất của đợt tuyển.`
                    : `Duyệt CV của ${item.full_name}?`,
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
            message: (item) => `Từ chối CV của ${item.full_name}? Ứng viên sẽ nhận email thông báo kết quả.`,
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
        tooltip: (item) => (item.status === "interview_scheduled" ? "Dời lịch phỏng vấn" : "Hẹn phỏng vấn"),
        hidden: (item) => !canManage.value || !["approved", "interview_scheduled"].includes(item.status),
        onClick: (item) => {
            selected.value = item;
            scheduleDialog.value = true;
        },
    },
    {
        icon: "mdi-clipboard-check-outline",
        tooltip: (item) => resultBlocker(item) ?? "Ghi kết quả phỏng vấn",
        color: "success",
        hidden: (item) =>
            !canManage.value || item.status !== "interview_scheduled" || !!item.question_sets?.length,
        disabled: (item) => !!resultBlocker(item),
        onClick: (item) => {
            selected.value = item;
            resultDialog.value = true;
        },
    },
    {
        icon: "mdi-clipboard-text-outline",
        tooltip: "Chấm điểm phỏng vấn (theo bộ câu hỏi)",
        color: "success",
        hidden: (item) =>
            !(canManage.value || canApprove.value) ||
            !item.question_sets?.length ||
            !item.interviews?.length ||
            !["interview_scheduled", "passed", "failed"].includes(item.status),
        onClick: (item) => {
            const interview = item.interviews.find((i) => i.status === "scheduled") ?? item.interviews[0];
            router.push({ name: "interview-scorecard", params: { id: interview.id } });
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
        // 1 nút cho mọi việc với offer: xem đủ thông tin, Admin duyệt / không duyệt / sửa tại chỗ.
        icon: "mdi-email-search-outline",
        tooltip: (item) => (canApprove.value && item.offer?.status === "pending_approval" ? "Xem & duyệt offer" : "Xem offer"),
        color: "primary",
        hidden: (item) => !item.offer || !(canApprove.value || canManage.value),
        onClick: (item) => openOfferReview(item),
    },
    {
        icon: "mdi-phone-check-outline",
        tooltip: "Ghi nhận phản hồi của ứng viên",
        color: "success",
        hidden: (item) => !canManage.value || item.offer?.status !== "sent",
        onClick: openOfferResponse,
    },
    {
        icon: "mdi-email-off-outline",
        tooltip: "Hủy offer",
        color: "error",
        hidden: (item) => !canManage.value || !["pending_approval", "sent"].includes(item.offer?.status),
        confirm: {
            title: "Hủy thư mời nhận việc",
            message: (item) =>
                item.offer.status === "sent"
                    ? `Hủy offer đã gửi cho ${item.full_name}? Nhớ báo lại cho ứng viên qua điện thoại / email.`
                    : `Hủy offer đang chờ duyệt của ${item.full_name}?`,
            confirmText: "Hủy offer",
        },
        onClick: async (item) => {
            try {
                await recruitmentService.withdrawOffer(item.offer.id);
                toast.success("Đã hủy offer.");
            } catch (e) {
                toast.error(firstError(e) ?? "Không hủy được offer.");
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
                // Phòng ban/chức vụ chốt trong offer (đợt tuyển chung không có sẵn).
                department_id: item.offer.department_id ?? opening.value.department_id,
                position_id: item.offer.position_id ?? opening.value.position_id,
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
        hidden: (item) => !canManage.value || !["pending", "rejected"].includes(item.status),
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
    if (canApprove.value) {
        loadQuestionSets();
    }
    if (canManage.value) {
        departmentStore.fetchTree();
    }
});
</script>
