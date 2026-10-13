<template>
    <!-- Tạo nhanh 1 bộ câu hỏi ngay trong lúc chọn bộ (QuestionSetWeightsDialog) — cùng API và
         quy tắc với trang "Bộ câu hỏi phỏng vấn" (chỉ Admin). Lỗi 422 hiện dưới từng ô. -->
    <v-dialog :model-value="modelValue" max-width="640" scrollable @update:model-value="$emit('update:modelValue', $event)">
        <v-card rounded="xl">
            <v-card-title class="pt-5 px-6">Tạo nhanh bộ câu hỏi</v-card-title>
            <v-card-text class="px-6">
                <div class="d-flex flex-wrap ga-3 mb-1">
                    <v-text-field
                        v-model="form.name"
                        label="Tên bộ câu hỏi"
                        variant="outlined"
                        density="comfortable"
                        style="min-width: 240px; flex: 1"
                        :error-messages="errors.name"
                    />
                    <v-text-field
                        v-model.number="form.pass_percent"
                        label="% đạt"
                        type="number"
                        min="1"
                        max="100"
                        suffix="%"
                        variant="outlined"
                        density="comfortable"
                        style="max-width: 130px"
                        :error-messages="errors.pass_percent"
                    />
                </div>

                <div class="text-subtitle-2 mb-2">Câu hỏi</div>
                <div v-for="(q, i) in form.questions" :key="q.key" class="question-row d-flex align-start ga-2 py-2">
                    <span class="text-body-2 font-weight-bold mt-3" style="width: 18px">{{ i + 1 }}.</span>
                    <div class="flex-grow-1 d-flex flex-column ga-1">
                        <v-textarea
                            v-model="q.content"
                            placeholder="Nội dung câu hỏi"
                            rows="1"
                            auto-grow
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                            :error-messages="errors[`questions.${i}.content`]"
                        />
                        <v-text-field
                            v-model="q.guidance"
                            placeholder="Gợi ý đánh giá cho người chấm (tuỳ chọn)"
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                            :error-messages="errors[`questions.${i}.guidance`]"
                        />
                    </div>
                    <v-text-field
                        v-model.number="q.weight"
                        label="Trọng số"
                        type="number"
                        min="1"
                        max="100"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        style="max-width: 100px"
                        :error-messages="errors[`questions.${i}.weight`]"
                    />
                    <v-btn
                        icon="mdi-close"
                        size="small"
                        variant="text"
                        class="mt-1"
                        :disabled="form.questions.length <= 1"
                        @click="form.questions.splice(i, 1)"
                    />
                </div>
                <div v-if="errors.questions" class="text-error text-caption">{{ errors.questions }}</div>
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" class="mt-1" :disabled="form.questions.length >= 50" @click="addQuestion">
                    Thêm câu hỏi
                </v-btn>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions class="px-6 pb-5">
                <span class="text-caption" style="opacity: 0.65">Sửa chi tiết sau ở trang "Bộ câu hỏi phỏng vấn".</span>
                <v-spacer />
                <v-btn variant="text" @click="$emit('update:modelValue', false)">Hủy</v-btn>
                <v-btn color="primary" variant="flat" :loading="saving" @click="save">Tạo bộ câu hỏi</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "created"]);

let keySeq = 0;
const blank = () => ({ key: ++keySeq, content: "", guidance: "", weight: 1 });
const form = ref({ name: "", pass_percent: 60, questions: [blank()] });
const errors = ref({});
const error = ref("");
const saving = ref(false);

function addQuestion() {
    form.value.questions.push(blank());
}

async function save() {
    saving.value = true;
    errors.value = {};
    error.value = "";
    try {
        const response = await recruitmentService.createQuestionSet({
            name: form.value.name.trim(),
            pass_percent: form.value.pass_percent,
            questions: form.value.questions.map((q) => ({
                content: q.content.trim(),
                guidance: q.guidance?.trim() || null,
                weight: q.weight,
            })),
        });
        emit("created", response.data.data);
        emit("update:modelValue", false);
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
        } else {
            error.value = e.response?.data?.message ?? "Không tạo được bộ câu hỏi.";
        }
    } finally {
        saving.value = false;
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        form.value = { name: "", pass_percent: 60, questions: [blank()] };
        errors.value = {};
        error.value = "";
    },
);
</script>

<style scoped>
.question-row + .question-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
</style>
