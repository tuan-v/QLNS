<template>
    <v-dialog
        :model-value="modelValue"
        max-width="560"
        scrollable
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <v-card v-if="interview" rounded="xl">
            <v-card-title class="d-flex align-start ga-2 pt-5 px-6">
                <div class="flex-grow-1">
                    <div class="text-caption font-weight-bold text-uppercase" style="opacity: 0.6">Buổi phỏng vấn</div>
                    <div class="text-h6 font-weight-bold text-wrap">{{ interview.candidate?.full_name }}</div>
                </div>
                <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
            </v-card-title>

            <v-card-text class="px-6">
                <div class="info-grid mb-4">
                    <div class="info-label">Thời gian</div>
                    <div class="font-weight-bold">{{ formatDateTime(interview.scheduled_at) }}</div>
                    <div class="info-label">Đợt tuyển</div>
                    <div>
                        <router-link
                            :to="{ name: 'recruitment-detail', params: { id: interview.opening?.id } }"
                            class="text-primary"
                        >
                            {{ interview.opening?.title }}
                        </router-link>
                        <span v-if="interview.opening?.department" style="opacity: 0.7">
                            · {{ interview.opening.department }}</span
                        >
                    </div>
                    <div class="info-label">Hình thức</div>
                    <div>
                        <template v-if="interview.format === 'online'">
                            Trực tuyến ·
                            <a :href="interview.location" target="_blank" rel="noopener" class="text-primary">{{
                                interview.location
                            }}</a>
                        </template>
                        <template v-else>Trực tiếp · {{ interview.location || interview.address_detail }}</template>
                    </div>
                    <div class="info-label">Liên hệ</div>
                    <div>
                        {{ interview.candidate?.email
                        }}<template v-if="interview.candidate?.phone"> · {{ interview.candidate.phone }}</template>
                    </div>
                    <div class="info-label">Phản hồi</div>
                    <div>
                        <!-- Đã phỏng vấn xong: hiện "Đã phỏng vấn" như bảng; phản hồi trước đó (nếu có) ghi ở dòng phụ. -->
                        <v-chip v-if="interview.status === 'completed'" size="small" variant="tonal" prepend-icon="mdi-check-all">
                            Đã phỏng vấn
                        </v-chip>
                        <StatusChip v-else :status="interview.confirmation_status" :map="INTERVIEW_CONFIRMATION_MAP" />
                        <div v-if="interview.confirmed_by" class="text-caption mt-1" style="opacity: 0.7">
                            <template v-if="interview.status === 'completed'">
                                Ứng viên {{ INTERVIEW_CONFIRMATION_MAP[interview.confirmation_status]?.label.toLowerCase() }} tham gia —
                            </template>
                            HR {{ interview.confirmed_by }} ghi nhận {{ formatDateTime(interview.confirmed_at) }}
                        </div>
                        <div v-if="interview.confirmation_note" class="text-caption">
                            Ghi chú: {{ interview.confirmation_note }}
                        </div>
                    </div>
                    <template v-if="interview.status === 'completed'">
                        <div class="info-label">Kết quả</div>
                        <div>
                            <v-chip
                                :color="interview.result === 'passed' ? 'success' : 'error'"
                                size="small"
                                variant="tonal"
                            >
                                {{ interview.result === "passed" ? "Đạt" : "Không đạt" }}
                            </v-chip>
                            <span v-if="interview.scorecard?.score_percent != null" class="text-caption ml-1">
                                {{ interview.scorecard.score_percent }}% theo phiếu chấm
                            </span>
                        </div>
                    </template>
                </div>

                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>

                <!-- HR ghi nhận phản hồi (chỉ với lịch đang hẹn) -->
                <template v-if="canManage && interview.status === 'scheduled'">
                    <div class="text-subtitle-2 font-weight-bold mb-2">Ghi nhận phản hồi của ứng viên</div>
                    <div class="d-flex flex-wrap ga-2 mb-3">
                        <v-btn
                            v-for="option in OPTIONS"
                            :key="option.value"
                            :color="choice === option.value ? option.color : undefined"
                            :variant="choice === option.value ? 'flat' : 'outlined'"
                            :prepend-icon="option.icon"
                            size="small"
                            @click="choose(option.value)"
                        >
                            {{ option.label }}
                        </v-btn>
                    </div>
                    <v-expand-transition>
                        <div v-if="choice">
                            <v-alert
                                v-if="choice === 'withdrawn'"
                                type="warning"
                                variant="tonal"
                                density="compact"
                                class="mb-2"
                            >
                                Lịch phỏng vấn sẽ bị hủy, ứng viên chuyển "Từ chối phỏng vấn" và trả lại suất cho đợt
                                tuyển.
                            </v-alert>
                            <v-alert
                                v-else-if="choice === 'reschedule_requested'"
                                type="info"
                                variant="tonal"
                                density="compact"
                                class="mb-2"
                            >
                                Ứng viên vẫn giữ suất. Bấm "Dời lịch" để hẹn giờ mới.
                            </v-alert>
                            <v-textarea
                                v-model="note"
                                :label="
                                    choice === 'withdrawn' ? 'Lý do ứng viên không tham gia *' : 'Ghi chú (tuỳ chọn)'
                                "
                                rows="2"
                                auto-grow
                                variant="outlined"
                                density="comfortable"
                                :error-messages="noteError"
                            />
                        </div>
                    </v-expand-transition>
                </template>
            </v-card-text>

            <v-card-actions class="px-6 pb-5 flex-wrap ga-2">
                <v-btn
                    v-if="canManage && interview.status === 'scheduled'"
                    variant="tonal"
                    prepend-icon="mdi-calendar-edit-outline"
                    @click="$emit('reschedule', interview)"
                >
                    Dời lịch
                </v-btn>
                <!-- Chấm theo bộ câu hỏi (đợt tuyển đã gắn bộ): khi ứng viên đã đồng ý, hoặc xem phiếu đã chấm. -->
                <v-btn
                    v-if="
                        interview.opening?.has_question_set &&
                        (interview.scorecard ||
                            (interview.status === 'scheduled' && interview.confirmation_status === 'confirmed'))
                    "
                    color="success"
                    variant="tonal"
                    prepend-icon="mdi-clipboard-text-outline"
                    :to="{ name: 'interview-scorecard', params: { id: interview.id } }"
                >
                    {{ interview.scorecard?.status === "submitted" ? "Xem phiếu chấm" : "Chấm điểm phỏng vấn" }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="$emit('update:modelValue', false)">Đóng</v-btn>
                <v-btn
                    v-if="canManage && interview.status === 'scheduled'"
                    color="primary"
                    variant="flat"
                    :disabled="!choice"
                    :loading="saving"
                    @click="save"
                >
                    Lưu & báo Admin
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import { useToastStore } from "../../stores/useToastStore";
import StatusChip from "../../components/common/StatusChip.vue";
import { INTERVIEW_CONFIRMATION_MAP } from "../../composables/recruitmentStatus";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    interview: { type: Object, default: null },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "saved", "reschedule"]);

const toast = useToastStore();

const OPTIONS = [
    { value: "confirmed", label: "Đồng ý tham gia", icon: "mdi-check", color: "success" },
    { value: "reschedule_requested", label: "Xin đổi lịch", icon: "mdi-calendar-sync-outline", color: "info" },
    { value: "withdrawn", label: "Không tham gia", icon: "mdi-close", color: "error" },
];

const choice = ref(null);
const note = ref("");
const noteError = ref("");
const error = ref("");
const saving = ref(false);

watch(
    () => [props.modelValue, props.interview?.id],
    () => {
        choice.value = null;
        note.value = "";
        noteError.value = "";
        error.value = "";
    },
);

function choose(value) {
    choice.value = value;
    noteError.value = "";
}

function formatDateTime(value) {
    if (!value) return "";
    return new Date(value).toLocaleString("vi-VN", {
        weekday: "short",
        hour: "2-digit",
        minute: "2-digit",
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

async function save() {
    if (choice.value === "withdrawn" && !note.value.trim()) {
        noteError.value = "Vui lòng ghi lý do ứng viên không tham gia";
        return;
    }
    saving.value = true;
    error.value = "";
    try {
        const response = await recruitmentService.confirmInterview(props.interview.id, {
            status: choice.value,
            note: note.value.trim() || null,
        });
        toast.success("Đã ghi nhận và báo Admin.");
        emit("saved", response.data.data);
        if (choice.value === "reschedule_requested") {
            emit("reschedule", response.data.data);
        } else {
            emit("update:modelValue", false);
        }
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = errors ? Object.values(errors)[0]?.[0] : (e.response?.data?.message ?? "Không lưu được.");
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.info-grid {
    display: grid;
    grid-template-columns: 110px 1fr;
    row-gap: 10px;
    column-gap: 12px;
}
.info-label {
    opacity: 0.65;
}
@media (max-width: 480px) {
    .info-grid {
        grid-template-columns: 1fr;
        row-gap: 2px;
    }
    .info-grid > :nth-child(even) {
        margin-bottom: 8px;
    }
}
</style>
