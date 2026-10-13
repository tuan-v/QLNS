<template>
    <!-- Các lần ứng tuyển trước của 1 người (CandidateHistoryService) — mới nhất trước. -->
    <div class="d-flex flex-column ga-2">
        <v-sheet v-for="item in items" :key="item.id" class="border rounded-lg pa-3" color="transparent">
            <div class="d-flex flex-wrap align-center ga-2">
                <div class="flex-grow-1 min-width-0">
                    <router-link
                        v-if="item.opening && !item.opening.deleted"
                        :to="{ name: 'recruitment-detail', params: { id: item.opening.id } }"
                        class="text-body-2 font-weight-bold text-primary"
                        @click="$emit('navigate')"
                    >
                        {{ item.opening.title }}
                    </router-link>
                    <span v-else class="text-body-2 font-weight-bold">
                        {{ item.opening?.title ?? "Đợt tuyển không còn" }}
                        <span v-if="item.opening?.deleted" class="text-caption" style="opacity: 0.6">(đã xóa)</span>
                    </span>
                    <div class="text-caption" style="opacity: 0.7">
                        Nộp {{ formatDate(item.applied_at) }} · {{ item.full_name }} · {{ item.email
                        }}<template v-if="item.phone"> · {{ item.phone }}</template>
                    </div>
                </div>
                <v-chip v-if="item.deleted" size="x-small" variant="outlined">CV đã xóa</v-chip>
                <StatusChip :status="item.stage?.key" :map="CANDIDATE_STAGE_MAP" />
            </div>

            <div class="d-flex flex-column ga-1 mt-2 text-caption">
                <div v-if="item.stage?.reason" style="opacity: 0.8">
                    <v-icon icon="mdi-information-outline" size="14" /> {{ item.stage.reason }}
                </div>
                <div v-if="item.review_note && item.status === 'rejected'">
                    <v-icon icon="mdi-close-circle-outline" size="14" color="error" /> Lý do từ chối CV:
                    {{ item.review_note }}
                    <span v-if="item.reviewed_by" style="opacity: 0.6">({{ item.reviewed_by }})</span>
                </div>
                <div v-if="item.interview">
                    <v-icon icon="mdi-account-voice" size="14" />
                    Phỏng vấn {{ formatDate(item.interview.scheduled_at) }}:
                    <strong>{{ interviewLabel(item.interview) }}</strong>
                    <template v-if="item.interview.score_percent != null">
                        · điểm {{ item.interview.score_percent }}%</template
                    >
                    <template v-if="item.interview.confirmation_note">
                        · {{ item.interview.confirmation_note }}</template
                    >
                </div>
                <div v-if="item.offer">
                    <v-icon icon="mdi-email-outline" size="14" />
                    Offer {{ money(item.offer.salary) }}:
                    <strong>{{ OFFER_STATUS_MAP[item.offer.status]?.label ?? item.offer.status }}</strong>
                    <template v-if="item.offer.response_note"> · {{ item.offer.response_note }}</template>
                </div>
                <div v-if="item.hired_employee">
                    <v-icon icon="mdi-account-check-outline" size="14" color="success" />
                    Đã nhận việc:
                    <router-link
                        :to="{ name: 'employee-detail', params: { id: item.hired_employee.id } }"
                        class="text-primary"
                        @click="$emit('navigate')"
                    >
                        {{ item.hired_employee.code }} – {{ item.hired_employee.full_name }}
                    </router-link>
                </div>
            </div>
        </v-sheet>
    </div>
</template>

<script setup>
import StatusChip from "../../components/common/StatusChip.vue";
import { CANDIDATE_STAGE_MAP, OFFER_STATUS_MAP } from "../../composables/recruitmentStatus";

defineProps({
    items: { type: Array, default: () => [] },
});

defineEmits(["navigate"]);

function interviewLabel(interview) {
    if (interview.status === "completed") return interview.result === "passed" ? "Đạt" : "Không đạt";
    if (interview.confirmation_status === "withdrawn") return "Không tham gia";
    if (interview.status === "cancelled") return "Đã hủy";
    return "Đã hẹn";
}

function formatDate(value) {
    if (!value) return "";
    const d = new Date(value);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

function money(value) {
    return `${Math.round(value ?? 0).toLocaleString("vi-VN")} ₫`;
}
</script>
