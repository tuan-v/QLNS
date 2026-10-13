<template>
    <div>
        <PageHeader title="Chấm điểm phỏng vấn" :subtitle="subtitle">
            <template #actions>
                <v-btn variant="tonal" prepend-icon="mdi-arrow-left" @click="goBack">Quay lại</v-btn>
            </template>
        </PageHeader>

        <div v-if="loading" class="d-flex justify-center py-10"><v-progress-circular indeterminate /></div>
        <v-alert v-else-if="loadError" type="error" variant="tonal">{{ loadError }}</v-alert>

        <template v-else-if="data">
            <!-- Thông tin buổi phỏng vấn -->
            <v-sheet class="border rounded-lg glass-panel pa-4 mb-4" color="transparent">
                <div class="d-flex flex-wrap align-center ga-4">
                    <div class="flex-grow-1">
                        <div class="text-h6 font-weight-bold">{{ interview.candidate?.full_name }}</div>
                        <div class="text-body-2" style="opacity: 0.75">
                            {{ formatDateTime(interview.scheduled_at) }} ·
                            <router-link
                                :to="{ name: 'recruitment-detail', params: { id: interview.opening?.id } }"
                                class="text-primary"
                            >
                                {{ interview.opening?.title }}
                            </router-link>
                            · {{ interview.format === "online" ? "Trực tuyến" : "Trực tiếp" }}
                        </div>
                    </div>
                    <StatusChip :status="interview.confirmation_status" :map="INTERVIEW_CONFIRMATION_MAP" />
                </div>
            </v-sheet>

            <!-- Chưa có phiếu -->
            <v-sheet v-if="!scorecard" class="border rounded-lg glass-panel pa-8 text-center" color="transparent">
                <v-icon icon="mdi-clipboard-text-outline" size="44" color="primary" class="mb-2" />
                <template v-if="data.start_blocker">
                    <div class="text-subtitle-1 font-weight-bold mb-1">Chưa chấm được</div>
                    <div style="opacity: 0.75">{{ data.start_blocker }}</div>
                </template>
                <template v-else>
                    <div class="text-subtitle-1 font-weight-bold mb-2">Bộ câu hỏi của đợt tuyển</div>
                    <div class="d-flex flex-wrap justify-center ga-2 mb-2">
                        <v-chip v-for="set in data.question_sets" :key="set.id" variant="tonal" color="primary">
                            {{ set.name
                            }}<template v-if="data.question_sets.length > 1"> · tỉ trọng {{ set.weight }}%</template> ·
                            đạt từ {{ set.pass_percent }}%
                        </v-chip>
                    </div>
                    <div class="mb-4" style="opacity: 0.75">
                        Đạt khi điểm tổng từ {{ startPassPercent }}%. Bấm để mở phiếu chấm cho buổi phỏng vấn này.
                    </div>
                    <v-alert v-if="actionError" type="error" variant="tonal" density="compact" class="mb-3 text-left">{{
                        actionError
                    }}</v-alert>
                    <v-btn
                        color="primary"
                        variant="flat"
                        size="large"
                        prepend-icon="mdi-play"
                        :loading="starting"
                        @click="start"
                    >
                        Bắt đầu chấm
                    </v-btn>
                </template>
            </v-sheet>

            <!-- Phiếu chấm -->
            <div v-else class="score-layout">
                <div class="d-flex flex-column ga-3">
                    <template v-for="section in sections" :key="section.index">
                        <!-- Tiêu đề phần — chỉ khi phiếu ghép nhiều bộ câu hỏi -->
                        <div v-if="sections.length > 1" class="d-flex flex-wrap align-center ga-2 mt-2">
                            <v-icon icon="mdi-format-list-numbered" color="primary" />
                            <span class="text-subtitle-1 font-weight-bold"
                                >Phần {{ section.index + 1 }}: {{ section.name }}</span
                            >
                            <v-chip size="small" variant="tonal">Tỉ trọng {{ section.weight }}%</v-chip>
                            <v-chip size="small" variant="tonal" :color="section.scored ? 'primary' : undefined">
                                {{ section.scored ? `${section.percent}%` : "chưa chấm" }}
                            </v-chip>
                        </div>
                        <v-sheet
                            v-for="item in section.items"
                            :key="item.id"
                            :class="[
                                'border rounded-lg glass-panel pa-4',
                                { 'question--missing': showMissing && !item.score },
                            ]"
                            color="transparent"
                        >
                            <div class="d-flex align-start ga-3">
                                <v-avatar
                                    color="primary"
                                    variant="tonal"
                                    size="32"
                                    class="text-caption font-weight-bold"
                                    >{{ items.indexOf(item) + 1 }}</v-avatar
                                >
                                <div class="flex-grow-1 min-width-0">
                                    <div class="text-body-1 font-weight-medium" style="white-space: pre-line">
                                        {{ item.content }}
                                    </div>
                                    <div v-if="item.guidance" class="text-caption mt-1" style="opacity: 0.7">
                                        Gợi ý: {{ item.guidance }}
                                    </div>
                                </div>
                                <v-chip size="small" variant="tonal"
                                    >Trọng số {{ item.weight }} · {{ share(item) }}%</v-chip
                                >
                            </div>
                            <div class="d-flex flex-wrap align-center ga-3 mt-3 pl-11">
                                <v-rating
                                    v-model="item.score"
                                    :length="5"
                                    :readonly="readonly"
                                    hover
                                    color="amber"
                                    active-color="amber"
                                    density="comfortable"
                                    :item-labels="readonly ? undefined : ['Rất kém', 'Kém', 'Đạt', 'Tốt', 'Rất tốt']"
                                    item-label-position="bottom"
                                />
                                <span class="text-body-2" style="opacity: 0.75">{{
                                    item.score ? `${item.score}/5` : "Chưa chấm"
                                }}</span>
                            </div>
                            <v-textarea
                                v-if="!readonly || item.note"
                                v-model="item.note"
                                class="mt-2 pl-11"
                                label="Ghi chú câu này (tuỳ chọn)"
                                rows="1"
                                auto-grow
                                variant="outlined"
                                density="compact"
                                hide-details
                                :readonly="readonly"
                            />
                        </v-sheet>
                    </template>

                    <v-textarea
                        v-model="overallNote"
                        label="Nhận xét chung"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        :readonly="readonly"
                    />
                </div>

                <!-- Tổng điểm (dính khi cuộn) -->
                <v-sheet class="border rounded-lg glass-panel pa-5 score-summary" color="transparent">
                    <div class="text-caption font-weight-bold text-uppercase" style="opacity: 0.6">
                        {{ scorecard.question_set_name }}
                    </div>
                    <div class="d-flex align-end ga-2 mt-2">
                        <span class="text-h3 font-weight-bold">{{ percent }}</span
                        ><span class="text-h6 mb-1">%</span>
                    </div>
                    <v-progress-linear
                        :model-value="percent"
                        :color="passing ? 'success' : 'error'"
                        height="10"
                        rounded
                        class="my-2"
                    />
                    <div v-if="sections.length > 1" class="d-flex flex-column ga-1 mb-2">
                        <div
                            v-for="section in sections"
                            :key="section.index"
                            class="d-flex align-center ga-2 text-body-2"
                        >
                            <span class="flex-grow-1 min-width-0 text-truncate">{{ section.name }}</span>
                            <span style="opacity: 0.6">×{{ section.weight }}%</span>
                            <strong style="min-width: 52px; text-align: right">{{
                                section.scored ? `${section.percent}%` : "—"
                            }}</strong>
                        </div>
                    </div>
                    <div class="text-body-2">
                        Đạt từ <strong>{{ scorecard.pass_percent }}%</strong> · đã chấm {{ scoredCount }}/{{
                            items.length
                        }}
                        câu
                    </div>

                    <v-alert
                        :type="
                            readonly
                                ? scorecard.result === 'passed'
                                    ? 'success'
                                    : 'error'
                                : passing
                                  ? 'success'
                                  : 'warning'
                        "
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                    >
                        <template v-if="readonly">
                            Kết quả: <strong>{{ scorecard.result === "passed" ? "Đạt" : "Không đạt" }}</strong>
                            <div class="text-caption">
                                Nộp bởi {{ scorecard.scored_by }} lúc {{ formatDateTime(scorecard.submitted_at) }}
                            </div>
                        </template>
                        <template v-else-if="scoredCount < items.length"
                            >Dự kiến: {{ passing ? "Đạt" : "Không đạt" }} (tính trên các câu đã chấm)</template
                        >
                        <template v-else
                            >Nếu nộp ngay: <strong>{{ passing ? "Đạt" : "Không đạt" }}</strong></template
                        >
                    </v-alert>

                    <v-alert v-if="actionError" type="error" variant="tonal" density="compact" class="mt-3">{{
                        actionError
                    }}</v-alert>

                    <template v-if="!readonly">
                        <div class="text-caption mt-3" style="opacity: 0.7">
                            <template v-if="savedAt">Đã lưu nháp lúc {{ savedAt }}.</template>
                            Nộp phiếu sẽ ghi kết quả buổi phỏng vấn và gửi email kết quả cho ứng viên — không sửa lại
                            được.
                        </div>
                        <div class="d-flex flex-column ga-2 mt-3">
                            <v-btn
                                variant="tonal"
                                prepend-icon="mdi-content-save-outline"
                                :loading="saving"
                                @click="saveDraft"
                                >Lưu nháp</v-btn
                            >
                            <v-btn
                                color="primary"
                                variant="flat"
                                prepend-icon="mdi-send-check-outline"
                                :disabled="saving"
                                @click="askSubmit"
                            >
                                Nộp & ra kết quả
                            </v-btn>
                        </div>
                    </template>
                </v-sheet>
            </div>
        </template>

        <v-dialog v-model="confirmOpen" max-width="440">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Nộp phiếu chấm?</v-card-title>
                <v-card-text>
                    {{ interview?.candidate?.full_name }} đạt <strong>{{ percent }}%</strong> (đạt từ
                    {{ scorecard?.pass_percent }}%) →
                    <strong :class="passing ? 'text-success' : 'text-error'">{{ passing ? "Đạt" : "Không đạt" }}</strong
                    >. Ứng viên sẽ nhận email kết quả; phiếu không sửa được sau khi nộp.
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="confirmOpen = false">Xem lại</v-btn>
                    <v-btn color="primary" variant="flat" :loading="submitting" @click="submit">Nộp phiếu</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import recruitmentService from "../../services/recruitmentService";
import { useToastStore } from "../../stores/useToastStore";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { INTERVIEW_CONFIRMATION_MAP } from "../../composables/recruitmentStatus";

const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const data = ref(null);
const items = ref([]);
const overallNote = ref("");
const loading = ref(true);
const loadError = ref("");
const actionError = ref("");
const starting = ref(false);
const saving = ref(false);
const submitting = ref(false);
const confirmOpen = ref(false);
const showMissing = ref(false);
const savedAt = ref("");
// Nội dung lần lưu gần nhất — chỉ tự lưu khi khác (gán dữ liệu lúc tải không tính là sửa).
let lastSaved = "";
let autosaveTimer = null;
const isDirty = () => !!scorecard.value && !readonly.value && JSON.stringify(payload()) !== lastSaved;

const interview = computed(() => data.value?.interview);
const scorecard = computed(() => data.value?.scorecard);
const readonly = computed(() => scorecard.value?.status === "submitted");
const subtitle = computed(() =>
    interview.value ? `${interview.value.candidate?.full_name} — chấm 1–5 sao từng câu, điểm tính theo trọng số.` : "",
);

const scoredCount = computed(() => items.value.filter((item) => item.score).length);
const round2 = (value) => Math.round(value * 100) / 100;

// % đạt trước khi mở phiếu = trung bình % đạt các bộ theo tỉ trọng.
const startPassPercent = computed(() =>
    round2((data.value?.question_sets ?? []).reduce((sum, s) => sum + s.pass_percent * s.weight, 0) / 100),
);

// % của 1 nhóm câu (khớp InterviewScorecardService::percent()). Đang chấm dở: chỉ tính câu đã chấm.
function groupPercent(list) {
    const scored = readonly.value ? list : list.filter((item) => item.score);
    const max = scored.reduce((sum, item) => sum + item.weight * 5, 0);
    return max ? round2((scored.reduce((sum, item) => sum + item.weight * (item.score || 0), 0) * 100) / max) : null;
}

// Các phần của phiếu (mỗi bộ câu hỏi 1 phần); phiếu cũ không có sections = 1 phần.
const sections = computed(() => {
    const defs = scorecard.value?.sections?.length
        ? scorecard.value.sections
        : [
              {
                  name: scorecard.value?.question_set_name ?? "",
                  weight: 100,
                  pass_percent: scorecard.value?.pass_percent,
              },
          ];
    return defs.map((def, index) => {
        const list = items.value.filter((item) => (item.section_index ?? 0) === index);
        const value = groupPercent(list);
        return {
            ...def,
            index,
            items: list,
            totalWeight: list.reduce((sum, i) => sum + i.weight, 0),
            percent: value ?? 0,
            scored: value !== null,
        };
    });
});

// % tổng = trung bình các phần theo tỉ trọng (khớp breakdown()). Đang chấm dở: chỉ các phần đã có câu chấm.
const percent = computed(() => {
    const counted = sections.value.filter((s) => s.scored);
    const weight = counted.reduce((sum, s) => sum + s.weight, 0);
    return weight ? round2(counted.reduce((sum, s) => sum + s.percent * s.weight, 0) / weight) : 0;
});
const passing = computed(() => percent.value >= (scorecard.value?.pass_percent ?? 100));

// Tỉ lệ câu trong PHẦN của nó.
function share(item) {
    const section = sections.value[item.section_index ?? 0];
    return section?.totalWeight ? Math.round((item.weight * 100) / section.totalWeight) : 0;
}

function formatDateTime(value) {
    if (!value) return "";
    return new Date(value).toLocaleString("vi-VN", {
        hour: "2-digit",
        minute: "2-digit",
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
    });
}

function firstError(e) {
    const errors = e.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message;
}

function applyScorecard(card) {
    data.value = { ...data.value, scorecard: card };
    items.value = (card?.items ?? []).map((item) => ({ ...item, note: item.note ?? "" }));
    overallNote.value = card?.overall_note ?? "";
    lastSaved = JSON.stringify(payload());
}

async function load() {
    loading.value = true;
    loadError.value = "";
    try {
        data.value = (await recruitmentService.scorecard(route.params.id)).data;
        applyScorecard(data.value.scorecard);
    } catch (e) {
        loadError.value = firstError(e) ?? "Không tải được phiếu chấm.";
    } finally {
        loading.value = false;
    }
}

async function start() {
    starting.value = true;
    actionError.value = "";
    try {
        applyScorecard((await recruitmentService.startScorecard(route.params.id)).data.data);
    } catch (e) {
        actionError.value = firstError(e) ?? "Không mở được phiếu chấm.";
    } finally {
        starting.value = false;
    }
}

function payload() {
    return {
        items: items.value.map((item) => ({ id: item.id, score: item.score || null, note: item.note?.trim() || null })),
        overall_note: overallNote.value?.trim() || null,
    };
}

async function saveDraft({ silent = false } = {}) {
    if (!scorecard.value || readonly.value) return;
    saving.value = true;
    if (!silent) actionError.value = "";
    try {
        const sent = payload();
        await recruitmentService.saveScorecard(scorecard.value.id, sent);
        lastSaved = JSON.stringify(sent);
        savedAt.value = new Date().toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
        if (!silent) toast.success("Đã lưu nháp phiếu chấm.");
    } catch (e) {
        actionError.value = firstError(e) ?? "Không lưu được phiếu chấm.";
    } finally {
        saving.value = false;
    }
}

function askSubmit() {
    actionError.value = "";
    if (scoredCount.value < items.value.length) {
        showMissing.value = true;
        actionError.value = `Còn ${items.value.length - scoredCount.value} câu chưa chấm điểm.`;
        return;
    }
    confirmOpen.value = true;
}

async function submit() {
    submitting.value = true;
    try {
        applyScorecard((await recruitmentService.submitScorecard(scorecard.value.id, payload())).data.data);
        confirmOpen.value = false;
        toast.success("Đã nộp phiếu chấm và ghi kết quả phỏng vấn.");
    } catch (e) {
        confirmOpen.value = false;
        actionError.value = firstError(e) ?? "Không nộp được phiếu chấm.";
    } finally {
        submitting.value = false;
    }
}

function goBack() {
    if (window.history.length > 1) router.back();
    else router.push({ name: "interview-schedule" });
}

// Tự lưu nháp 3 giây sau lần chấm/ghi chú cuối — không mất điểm nếu lỡ đóng trang.
watch(
    [items, overallNote],
    () => {
        clearTimeout(autosaveTimer);
        if (!isDirty()) return;
        autosaveTimer = setTimeout(() => isDirty() && saveDraft({ silent: true }), 3000);
    },
    { deep: true },
);

onMounted(load);
onBeforeUnmount(() => {
    clearTimeout(autosaveTimer);
    if (isDirty()) saveDraft({ silent: true });
});
</script>

<style scoped>
.score-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 16px;
    align-items: start;
}
.score-summary {
    position: sticky;
    top: 80px;
}
.question--missing {
    border-color: rgb(var(--v-theme-error)) !important;
}
@media (max-width: 960px) {
    .score-layout {
        grid-template-columns: 1fr;
    }
    .score-summary {
        position: static;
        order: -1;
    }
}
</style>
