<template>
    <!-- Chọn các bộ câu hỏi + tỉ trọng % (tổng 100) — dùng cho đợt tuyển và mặc định của chức vụ.
         Phát "save" với [{ id, weight }] theo thứ tự hiển thị (= thứ tự các phần trên phiếu chấm). -->
    <v-dialog :model-value="modelValue" max-width="600" scrollable @update:model-value="$emit('update:modelValue', $event)">
        <v-card rounded="xl">
            <v-card-title class="pt-5 px-6">{{ title }}</v-card-title>
            <v-card-subtitle v-if="subtitle" class="px-6" style="white-space: normal">{{ subtitle }}</v-card-subtitle>

            <v-card-text class="px-6">
                <div v-if="!rows.length" class="text-body-2 py-4 text-center" style="opacity: 0.7">
                    Chưa chọn bộ câu hỏi nào{{ emptyHint ? ` — ${emptyHint}` : "" }}.
                </div>

                <div v-for="(row, i) in rows" :key="row.key" class="d-flex align-start ga-2 mb-2">
                    <span class="text-body-2 font-weight-bold mt-3" style="width: 18px">{{ i + 1 }}.</span>
                    <v-select
                        v-model="row.id"
                        :items="optionsFor(row)"
                        item-title="title"
                        item-value="value"
                        placeholder="Chọn bộ câu hỏi"
                        density="compact"
                        hide-details="auto"
                        class="flex-grow-1"
                    />
                    <v-text-field
                        v-model.number="row.weight"
                        type="number"
                        min="1"
                        max="100"
                        suffix="%"
                        density="compact"
                        hide-details
                        style="max-width: 100px"
                    />
                    <div class="d-flex flex-column">
                        <v-btn icon="mdi-chevron-up" size="x-small" variant="text" :disabled="i === 0" @click="move(i, -1)" />
                        <v-btn icon="mdi-chevron-down" size="x-small" variant="text" :disabled="i === rows.length - 1" @click="move(i, 1)" />
                    </div>
                    <v-btn icon="mdi-close" size="small" variant="text" class="mt-1" @click="rows.splice(i, 1)" />
                </div>

                <div class="d-flex flex-wrap align-center ga-2 mt-2">
                    <v-btn size="small" variant="text" prepend-icon="mdi-plus" :disabled="rows.length >= available.length" @click="addRow">
                        Thêm bộ
                    </v-btn>
                    <v-btn v-if="canCreate" size="small" variant="text" color="primary" prepend-icon="mdi-plus-box-outline" @click="quickOpen = true">
                        Tạo bộ câu hỏi mới
                    </v-btn>
                    <v-btn v-if="rows.length > 1" size="small" variant="text" prepend-icon="mdi-scale-balance" @click="splitEvenly">
                        Chia đều
                    </v-btn>
                    <v-spacer />
                    <span v-if="rows.length" class="text-body-2" :class="totalOk ? 'text-success' : 'text-error'">
                        Tổng tỉ trọng: <strong>{{ total }}%</strong>{{ totalOk ? "" : " (cần đúng 100%)" }}
                    </span>
                </div>

                <v-alert v-if="rows.length && totalOk && passPercent !== null" type="info" variant="tonal" density="compact" class="mt-3">
                    Ứng viên <strong>Đạt</strong> khi điểm tổng (trung bình các phần theo tỉ trọng) từ <strong>{{ passPercent }}%</strong>
                    — tính từ % đạt của từng bộ theo tỉ trọng.
                </v-alert>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</v-alert>
            </v-card-text>

            <v-card-actions class="px-6 pb-5">
                <!-- Nút phụ (vd "Lấy theo chức vụ") — nhận apply(list) để điền sẵn danh sách. -->
                <slot name="extra" :apply="apply" />
                <v-spacer />
                <v-btn variant="text" @click="$emit('update:modelValue', false)">Hủy</v-btn>
                <v-btn color="primary" variant="flat" :loading="saving" :disabled="!canSave" @click="save">Lưu</v-btn>
            </v-card-actions>
        </v-card>
        <QuickQuestionSetDialog v-model="quickOpen" @created="onSetCreated" />
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import QuickQuestionSetDialog from "./QuickQuestionSetDialog.vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: "Bộ câu hỏi phỏng vấn" },
    subtitle: { type: String, default: "" },
    emptyHint: { type: String, default: "" },
    // Mọi bộ câu hỏi (từ GET /question-sets).
    sets: { type: Array, default: () => [] },
    // Đang gắn: [{ id, weight }].
    value: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    error: { type: String, default: "" },
    // Hiện nút "Tạo bộ câu hỏi mới" (chỉ Admin — recruitment.approve).
    canCreate: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "save", "set-created"]);

let keySeq = 0;
const rows = ref([]);

// Bộ vừa tạo nhanh trong dialog (trang cha tải lại danh sách sau, tới lúc đó vẫn chọn được).
const createdSets = ref([]);
const allSets = computed(() => [...props.sets, ...createdSets.value.filter((c) => !props.sets.some((s) => s.id === c.id))]);
// Bộ đang dùng được + bộ đã ngừng nhưng đang gắn sẵn (để vẫn thấy tên).
const available = computed(() => allSets.value.filter((s) => s.is_active || props.value.some((v) => v.id === s.id)));
const byId = computed(() => Object.fromEntries(allSets.value.map((s) => [s.id, s])));
const quickOpen = ref(false);

// Tạo xong -> chọn luôn: điền vào dòng đang trống, không có thì thêm dòng (tỉ trọng = phần còn thiếu).
function onSetCreated(set) {
    createdSets.value.push(set);
    const empty = rows.value.find((r) => !r.id);
    if (empty) {
        empty.id = set.id;
    } else {
        rows.value.push({ key: ++keySeq, id: set.id, weight: Math.max(1, 100 - total.value) });
    }
    emit("set-created", set);
}
const total = computed(() => rows.value.reduce((sum, r) => sum + (Number(r.weight) || 0), 0));
const totalOk = computed(() => total.value === 100);
const complete = computed(() => rows.value.every((r) => r.id && Number(r.weight) >= 1));
const canSave = computed(() => complete.value && (rows.value.length === 0 || totalOk.value));
const passPercent = computed(() => {
    if (!complete.value || !totalOk.value) return null;
    const value = rows.value.reduce((sum, r) => sum + (byId.value[r.id]?.pass_percent ?? 0) * r.weight, 0) / 100;
    return Math.round(value * 100) / 100;
});

function optionsFor(row) {
    const taken = new Set(rows.value.filter((r) => r !== row).map((r) => r.id));
    return available.value
        .filter((s) => !taken.has(s.id))
        .map((s) => ({
            title: `${s.name} (đạt từ ${s.pass_percent}%)${s.is_active ? "" : " — đã ngừng dùng"}`,
            value: s.id,
        }));
}

function apply(list) {
    rows.value = list.map((r) => ({ key: ++keySeq, id: r.id, weight: r.weight }));
}

function addRow() {
    rows.value.push({ key: ++keySeq, id: null, weight: Math.max(0, 100 - total.value) || 1 });
}

function move(index, step) {
    const [row] = rows.value.splice(index, 1);
    rows.value.splice(index + step, 0, row);
}

// Chia đều, phần dư cộng vào bộ đầu để tổng đúng 100.
function splitEvenly() {
    const n = rows.value.length;
    const base = Math.floor(100 / n);
    rows.value.forEach((r, i) => (r.weight = base + (i === 0 ? 100 - base * n : 0)));
}

function save() {
    emit("save", rows.value.map((r) => ({ id: r.id, weight: Number(r.weight) })));
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) apply(props.value);
    },
);
</script>
