<template>
    <div>
        <PageHeader
            title="Bộ câu hỏi phỏng vấn"
            subtitle="Admin soạn câu hỏi, trọng số và % đạt; gắn sẵn bộ mặc định cho từng chức vụ — đợt tuyển mới tự lấy, vẫn đổi được ở trang chi tiết đợt tuyển."
        >
            <template #actions>
                <v-btn
                    v-if="canEdit"
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="startNew"
                >
                    Bộ câu hỏi
                </v-btn>
            </template>
        </PageHeader>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>

        <v-tabs v-model="tab" color="primary" class="mb-3">
            <v-tab value="sets" prepend-icon="mdi-clipboard-text-outline">Bộ câu hỏi</v-tab>
            <v-tab value="positions" prepend-icon="mdi-briefcase-outline">Mặc định theo chức vụ</v-tab>
        </v-tabs>

        <!-- Bộ câu hỏi mặc định của từng chức vụ (tỉ trọng %) -->
        <v-sheet v-if="tab === 'positions'" class="border rounded-lg glass-panel pa-4" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3 mb-3">
                <div class="text-body-2 flex-grow-1" style="opacity: 0.75; min-width: 240px">
                    Đợt tuyển mới của chức vụ tự chép các bộ này (kèm tỉ trọng). Đổi ở đây không ảnh hưởng đợt tuyển đã
                    tạo.
                </div>
                <SearchField v-model="positionSearch" placeholder="Tìm chức vụ, phòng ban" style="max-width: 280px" />
            </div>
            <div v-if="positionsLoading" class="d-flex justify-center py-6">
                <v-progress-circular indeterminate size="24" />
            </div>
            <div v-else-if="!filteredPositions.length" class="pa-4 text-center" style="opacity: 0.6">
                Không có chức vụ nào.
            </div>
            <div v-else class="d-flex flex-column">
                <div
                    v-for="position in filteredPositions"
                    :key="position.id"
                    class="position-row d-flex flex-wrap align-center ga-2 py-3"
                >
                    <div class="min-width-0" style="flex: 1 1 200px">
                        <div class="text-body-2 font-weight-bold">{{ position.name }}</div>
                        <div class="text-caption" style="opacity: 0.65">{{ position.department ?? "—" }}</div>
                    </div>
                    <div class="d-flex flex-wrap align-center ga-1" style="flex: 2 1 260px">
                        <template v-if="position.question_sets.length">
                            <v-chip
                                v-for="set in position.question_sets"
                                :key="set.id"
                                size="small"
                                variant="tonal"
                                color="primary"
                            >
                                {{ set.name
                                }}<template v-if="position.question_sets.length > 1"> · {{ set.weight }}%</template>
                            </v-chip>
                        </template>
                        <span v-else class="text-caption" style="opacity: 0.6">Chưa có bộ mặc định</span>
                    </div>
                    <v-btn
                        v-if="canEdit"
                        size="small"
                        variant="text"
                        prepend-icon="mdi-pencil-outline"
                        @click="editPosition(position)"
                    >
                        Chọn bộ
                    </v-btn>
                </div>
            </div>
        </v-sheet>

        <QuestionSetWeightsDialog
            v-model="positionDialog"
            :title="editingPosition ? `Bộ câu hỏi mặc định — ${editingPosition.name}` : ''"
            subtitle="Đợt tuyển mới của chức vụ này tự lấy các bộ dưới đây (kèm tỉ trọng); Admin vẫn đổi được cho từng đợt."
            empty-hint="đợt tuyển mới sẽ chưa có bộ câu hỏi"
            :sets="sets"
            :value="editingPosition?.question_sets ?? []"
            :saving="positionSaving"
            :error="positionError"
            :can-create="canEdit"
            @save="savePosition"
            @set-created="load"
        />

        <div v-show="tab === 'sets'" class="sets-layout">
            <!-- Danh sách bộ -->
            <v-sheet class="border rounded-lg glass-panel pa-2" color="transparent">
                <div v-if="loading" class="d-flex justify-center py-6">
                    <v-progress-circular indeterminate size="24" />
                </div>
                <div v-else-if="!sets.length" class="pa-4 text-center" style="opacity: 0.6">
                    Chưa có bộ câu hỏi nào.
                </div>
                <v-list v-else density="compact" nav>
                    <v-list-item
                        v-for="set in sets"
                        :key="set.id"
                        :active="editing?.id === set.id"
                        rounded="lg"
                        @click="edit(set)"
                    >
                        <v-list-item-title class="font-weight-medium">{{ set.name }}</v-list-item-title>
                        <v-list-item-subtitle>
                            {{ set.questions.length }} câu · đạt từ {{ set.pass_percent }}% ·
                            {{ set.openings_count }} đợt tuyển · {{ set.positions_count }} chức vụ
                            <template v-if="!set.is_active"> · ngừng dùng</template>
                        </v-list-item-subtitle>
                    </v-list-item>
                </v-list>
            </v-sheet>

            <!-- Soạn / xem bộ -->
            <v-sheet class="border rounded-lg glass-panel pa-5" color="transparent">
                <div v-if="!editing" class="text-center py-10" style="opacity: 0.6">
                    Chọn 1 bộ câu hỏi bên trái để {{ canEdit ? "sửa" : "xem"
                    }}<template v-if="canEdit">, hoặc bấm "+ Bộ câu hỏi"</template>.
                </div>
                <v-form v-else ref="formRef" :disabled="!canEdit" @submit.prevent="save">
                    <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{
                        error
                    }}</v-alert>

                    <div class="d-flex flex-wrap ga-3 mb-2">
                        <v-text-field
                            v-model="editing.name"
                            label="Tên bộ câu hỏi"
                            variant="outlined"
                            density="comfortable"
                            style="min-width: 260px"
                            :rules="[(v) => !!v?.trim() || 'Vui lòng nhập tên bộ câu hỏi']"
                            :error-messages="errors.name"
                        />
                        <v-text-field
                            v-model.number="editing.pass_percent"
                            label="% đạt"
                            type="number"
                            suffix="%"
                            variant="outlined"
                            density="comfortable"
                            style="max-width: 140px"
                            :rules="[(v) => (v >= 1 && v <= 100) || 'Từ 1 đến 100']"
                            :error-messages="errors.pass_percent"
                        />
                        <v-switch
                            v-model="editing.is_active"
                            label="Đang dùng"
                            color="primary"
                            inset
                            hide-details
                            density="compact"
                        />
                    </div>
                    <v-textarea
                        v-model="editing.description"
                        label="Mô tả (tuỳ chọn)"
                        rows="2"
                        auto-grow
                        variant="outlined"
                        density="comfortable"
                    />

                    <div class="d-flex align-center mb-1 mt-2">
                        <div class="text-subtitle-1 font-weight-bold">Câu hỏi</div>
                        <v-spacer />
                        <span class="text-caption" style="opacity: 0.7">Tổng trọng số: {{ totalWeight }}</span>
                    </div>
                    <div class="text-caption mb-2" style="opacity: 0.7">
                        Mỗi câu chấm 5 mức: Rất kém (1), Kém (2), Đạt (3), Tốt (4), Rất tốt (5). Điểm = Σ(trọng số ×
                        mức) ÷ Σ(trọng số × 5). Từ
                        {{ editing.pass_percent || 0 }}% trở lên là Đạt.
                    </div>

                    <div v-for="(question, index) in editing.questions" :key="question.key" class="question-row py-3">
                        <div class="d-flex align-start ga-2">
                            <div v-if="canEdit" class="d-flex flex-column">
                                <v-btn
                                    icon="mdi-chevron-up"
                                    variant="text"
                                    size="x-small"
                                    :disabled="index === 0"
                                    @click="move(index, -1)"
                                />
                                <v-btn
                                    icon="mdi-chevron-down"
                                    variant="text"
                                    size="x-small"
                                    :disabled="index === editing.questions.length - 1"
                                    @click="move(index, 1)"
                                />
                            </div>
                            <div class="flex-grow-1 d-flex flex-column ga-2">
                                <v-textarea
                                    v-model="question.content"
                                    :label="`Câu ${index + 1}`"
                                    rows="1"
                                    auto-grow
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                    :rules="[(v) => !!v?.trim() || 'Vui lòng nhập câu hỏi']"
                                    :error-messages="errors[`questions.${index}.content`]"
                                />
                                <v-text-field
                                    v-model="question.guidance"
                                    label="Gợi ý đánh giá cho người chấm (tuỳ chọn)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                />
                            </div>
                            <div style="width: 120px">
                                <v-text-field
                                    v-model.number="question.weight"
                                    label="Trọng số"
                                    type="number"
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                    :rules="[(v) => (Number.isInteger(v) && v >= 1 && v <= 100) || '1–100']"
                                    :error-messages="errors[`questions.${index}.weight`]"
                                />
                                <div class="text-caption mt-1 text-center" style="opacity: 0.7">
                                    ≈ {{ share(question) }}% điểm
                                </div>
                            </div>
                            <v-btn
                                v-if="canEdit"
                                icon="mdi-delete-outline"
                                variant="text"
                                color="error"
                                size="small"
                                :disabled="editing.questions.length === 1"
                                @click="editing.questions.splice(index, 1)"
                            />
                        </div>
                    </div>

                    <template v-if="canEdit">
                        <v-btn variant="tonal" prepend-icon="mdi-plus" size="small" class="mt-2" @click="addQuestion"
                            >Thêm câu hỏi</v-btn
                        >
                        <div v-if="errors.questions" class="text-error text-caption mt-1">{{ errors.questions }}</div>

                        <v-alert
                            v-if="editing.id && (editing.openings_count || editing.positions_count)"
                            type="info"
                            variant="tonal"
                            density="compact"
                            class="mt-4"
                        >
                            Bộ này đang gắn với {{ editing.openings_count }} đợt tuyển,
                            {{ editing.positions_count }} chức vụ. Sửa chỉ áp dụng cho phiếu chấm mở SAU khi lưu — phiếu
                            đang chấm/đã nộp giữ nguyên câu hỏi cũ.
                        </v-alert>

                        <div class="d-flex ga-2 mt-4">
                            <v-btn
                                v-if="editing.id"
                                variant="text"
                                color="error"
                                prepend-icon="mdi-delete-outline"
                                :loading="deleting"
                                @click="remove"
                            >
                                {{ confirmingDelete ? "Bấm lần nữa để xóa" : "Xóa bộ câu hỏi" }}
                            </v-btn>
                            <v-spacer />
                            <v-btn variant="text" @click="editing = null">Bỏ thay đổi</v-btn>
                            <v-btn color="primary" variant="flat" type="submit" :loading="saving">Lưu bộ câu hỏi</v-btn>
                        </div>
                    </template>
                </v-form>
            </v-sheet>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import PageHeader from "../../components/common/PageHeader.vue";
import SearchField from "../../components/common/SearchField.vue";
import QuestionSetWeightsDialog from "./QuestionSetWeightsDialog.vue";
import { normalizeVietnamese } from "../../composables/normalizeVietnamese";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { useRouteAction } from "../../composables/useRouteAction";

const auth = useAuthStore();
const toast = useToastStore();

const canEdit = computed(() => auth.permissions.includes("recruitment.approve"));

const sets = ref([]);
const tab = ref("sets");

/* ---- Bộ câu hỏi mặc định theo chức vụ ---- */
const positions = ref([]);
const positionsLoading = ref(false);
const positionSearch = ref("");
const positionDialog = ref(false);
const editingPosition = ref(null);
const positionSaving = ref(false);
const positionError = ref("");
const filteredPositions = computed(() => {
    const keyword = normalizeVietnamese(positionSearch.value ?? "");
    if (!keyword) return positions.value;
    return positions.value.filter((p) => normalizeVietnamese(`${p.name} ${p.department ?? ""}`).includes(keyword));
});

async function loadPositions() {
    positionsLoading.value = !positions.value.length;
    try {
        positions.value = (await recruitmentService.positionQuestionSets()).data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không tải được danh sách chức vụ.";
    } finally {
        positionsLoading.value = false;
    }
}

function editPosition(position) {
    editingPosition.value = position;
    positionError.value = "";
    positionDialog.value = true;
}

async function savePosition(rows) {
    positionSaving.value = true;
    positionError.value = "";
    try {
        await recruitmentService.assignPositionQuestionSets(editingPosition.value.id, rows);
        positionDialog.value = false;
        toast.success(`Đã lưu bộ câu hỏi mặc định cho "${editingPosition.value.name}".`);
        await Promise.all([loadPositions(), load()]);
    } catch (e) {
        const errs = e.response?.data?.errors;
        positionError.value = errs ? Object.values(errs)[0]?.[0] : (e.response?.data?.message ?? "Không lưu được.");
    } finally {
        positionSaving.value = false;
    }
}
const loading = ref(false);
const loadError = ref("");
const editing = ref(null);
const formRef = ref(null);
const saving = ref(false);
const deleting = ref(false);
const confirmingDelete = ref(false);
const error = ref("");
const errors = ref({});
let keySeq = 0;

const totalWeight = computed(() =>
    (editing.value?.questions ?? []).reduce((sum, q) => sum + (Number(q.weight) || 0), 0),
);

function share(question) {
    return totalWeight.value ? Math.round(((Number(question.weight) || 0) * 100) / totalWeight.value) : 0;
}

async function load() {
    loading.value = !sets.value.length;
    loadError.value = "";
    try {
        sets.value = (await recruitmentService.questionSets()).data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không tải được bộ câu hỏi.";
    } finally {
        loading.value = false;
    }
}

function blankQuestion() {
    return { key: ++keySeq, content: "", guidance: "", weight: 1 };
}

function reset() {
    confirmingDelete.value = false;
    error.value = "";
    errors.value = {};
}

function edit(set) {
    reset();
    editing.value = {
        ...set,
        description: set.description ?? "",
        questions: set.questions.map((q) => ({ ...q, key: ++keySeq, guidance: q.guidance ?? "" })),
    };
}

function startNew() {
    reset();
    editing.value = {
        id: null,
        name: "",
        description: "",
        pass_percent: 60,
        is_active: true,
        openings_count: 0,
        questions: [blankQuestion()],
    };
}

function addQuestion() {
    editing.value.questions.push(blankQuestion());
}

function move(index, step) {
    const list = editing.value.questions;
    [list[index], list[index + step]] = [list[index + step], list[index]];
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;
    saving.value = true;
    reset();
    const payload = {
        name: editing.value.name.trim(),
        description: editing.value.description?.trim() || null,
        pass_percent: editing.value.pass_percent,
        is_active: editing.value.is_active,
        questions: editing.value.questions.map((q) => ({
            content: q.content.trim(),
            guidance: q.guidance?.trim() || null,
            weight: q.weight,
        })),
    };
    try {
        const response = editing.value.id
            ? await recruitmentService.updateQuestionSet(editing.value.id, payload)
            : await recruitmentService.createQuestionSet(payload);
        toast.success("Đã lưu bộ câu hỏi.");
        await load();
        edit(sets.value.find((s) => s.id === response.data.data.id) ?? response.data.data);
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
            error.value = "Vui lòng kiểm tra lại các ô báo đỏ.";
        } else {
            error.value = e.response?.data?.message ?? "Không lưu được bộ câu hỏi.";
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;
        return;
    }
    deleting.value = true;
    try {
        await recruitmentService.removeQuestionSet(editing.value.id);
        toast.success("Đã xóa bộ câu hỏi.");
        editing.value = null;
        await load();
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không xóa được bộ câu hỏi.";
    } finally {
        deleting.value = false;
    }
}

watch(tab, (value) => value === "positions" && loadPositions());

useRealtimeRefresh(() => Promise.all([load(), tab.value === "positions" ? loadPositions() : null]), {
    shared: [{ resource: "recruitment", permission: ["recruitment.manage", "recruitment.approve"] }],
});
useRouteAction({ create: () => canEdit.value && startNew() });

onMounted(load);
</script>

<style scoped>
.sets-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 16px;
    align-items: start;
}
.position-row + .position-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.question-row + .question-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
@media (max-width: 960px) {
    .sets-layout {
        grid-template-columns: 1fr;
    }
}
</style>
