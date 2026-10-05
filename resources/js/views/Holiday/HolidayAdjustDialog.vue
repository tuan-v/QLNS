<template>
    <v-dialog
        :model-value="modelValue"
        max-width="760"
        scrollable
        persistent
        @update:model-value="requestClose"
    >
        <v-card rounded="xl" elevation="12" class="glass-panel">
            <v-card-title class="d-flex align-center pa-5 pb-2">
                <div>
                    <div class="text-h6 font-weight-bold">
                        Điều chỉnh lịch nghỉ — {{ group?.name }}
                    </div>
                    <div class="text-body-2" style="opacity: 0.7">
                        Đối chiếu thông báo chính thức của Nhà nước. Các thay đổi chỉ là
                        bản nháp — chưa lưu cho tới khi bấm "Lưu &amp; xác nhận lịch".
                    </div>
                </div>
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" @click="requestClose" />
            </v-card-title>

            <v-card-text class="px-5 pb-5">
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
                    {{ error }}
                </v-alert>

                <template v-if="group">
                    <v-alert
                        v-if="dirty"
                        type="info"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                    >
                        Có {{ changeCount }} thay đổi chưa lưu.
                    </v-alert>
                    <v-alert
                        v-else
                        :type="group.confirmed ? 'success' : 'warning'"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                    >
                        {{
                            group.confirmed
                                ? "Lịch đã được xác nhận — hệ thống sẽ tự thông báo cho nhân viên."
                                : "Lịch chưa được xác nhận — nhân viên chưa được thông báo."
                        }}
                    </v-alert>

                    <div class="text-subtitle-2 font-weight-bold mb-2">
                        Các ngày nghỉ sau điều chỉnh ({{ previewCount }} ngày)
                    </div>
                    <v-table density="compact" class="mb-4">
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.key"
                                :class="{ 'row-removed': row.removed }"
                            >
                                <td style="width: 150px">
                                    {{ weekdayOf(row.date) }} {{ formatDate(row.date) }}
                                </td>
                                <td>
                                    {{ row.name }}
                                    <div v-if="row.makeup_date" class="text-caption text-info">
                                        Đi làm bù {{ weekdayOf(row.makeup_date) }}
                                        {{ formatDate(row.makeup_date) }}
                                    </div>
                                </td>
                                <td style="width: 150px">
                                    <v-chip size="x-small" variant="tonal" :color="row.chip.color">
                                        {{ row.chip.label }}
                                    </v-chip>
                                </td>
                                <td class="text-right" style="width: 56px">
                                    <v-btn
                                        :icon="row.removed ? 'mdi-undo' : 'mdi-close'"
                                        size="small"
                                        variant="text"
                                        :color="row.removed ? 'primary' : 'error'"
                                        @click="toggleRow(row)"
                                    >
                                        <v-icon :icon="row.removed ? 'mdi-undo' : 'mdi-close'" />
                                        <v-tooltip activator="parent" location="top">
                                            {{ row.removed ? "Hoàn tác" : "Bỏ ngày này" }}
                                        </v-tooltip>
                                    </v-btn>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <div class="text-subtitle-2 font-weight-bold mb-1">Thêm ngày nghỉ</div>
                    <div class="d-flex ga-2 align-start flex-wrap mb-4">
                        <InputDate v-model="addDate" style="max-width: 220px" />
                        <v-btn color="primary" variant="tonal" :disabled="!addDate" @click="addDay">
                            Thêm vào nháp
                        </v-btn>
                    </div>

                    <div class="text-subtitle-2 font-weight-bold mb-1">Hoán đổi ngày làm việc</div>
                    <div class="text-body-2 mb-2" style="opacity: 0.7">
                        Nghỉ một ngày thường để nối kỳ nghỉ, đi làm bù vào Thứ 7/Chủ nhật.
                    </div>
                    <div class="d-flex ga-2 align-start flex-wrap mb-2">
                        <InputDate v-model="swap.off_date" label="Ngày nghỉ (T2–T6)" style="max-width: 220px" />
                        <InputDate v-model="swap.makeup_date" label="Ngày làm bù (T7/CN)" style="max-width: 220px" />
                        <v-btn
                            color="primary"
                            variant="tonal"
                            :disabled="!swap.off_date || !swap.makeup_date"
                            @click="addSwap"
                        >
                            Thêm vào nháp
                        </v-btn>
                    </div>
                </template>
            </v-card-text>

            <v-card-actions class="px-5 pb-5">
                <v-btn
                    v-if="group?.confirmed && !dirty"
                    variant="text"
                    :loading="saving === 'unconfirm'"
                    @click="unconfirm"
                >
                    Bỏ xác nhận
                </v-btn>
                <v-btn v-if="dirty" variant="text" @click="resetDraft">Hoàn tác tất cả</v-btn>
                <v-spacer />
                <v-btn variant="text" @click="requestClose">{{ dirty ? "Hủy" : "Đóng" }}</v-btn>
                <v-btn
                    v-if="group && (dirty || !group.confirmed)"
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-check-decagram-outline"
                    :loading="saving === 'apply'"
                    @click="save"
                >
                    {{ dirty ? "Lưu & xác nhận lịch" : "Xác nhận lịch" }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-dialog v-model="discardDialog" max-width="420">
        <v-card rounded="xl" class="glass-panel">
            <v-card-title class="pa-5 pb-2 font-weight-bold">Bỏ các thay đổi chưa lưu?</v-card-title>
            <v-card-text class="px-5">
                Có {{ changeCount }} thay đổi chưa lưu. Đóng lại sẽ bỏ toàn bộ, lịch nghỉ giữ nguyên.
            </v-card-text>
            <v-card-actions class="px-5 pb-5">
                <v-spacer />
                <v-btn variant="text" @click="discardDialog = false">Tiếp tục chỉnh</v-btn>
                <v-btn color="error" variant="flat" @click="discardAndClose">Bỏ thay đổi</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import holidayService from "../../services/holidayService";
import InputDate from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    groupCode: { type: String, default: null },
});
const emit = defineEmits(["update:modelValue", "changed"]);

const toast = useToastStore();
const group = ref(null);
const error = ref("");
const saving = ref("");
const discardDialog = ref(false);

// Bản nháp: chưa gửi gì lên máy chủ cho tới khi bấm lưu.
const removedIds = ref(new Set());
const addedDates = ref([]);
const swaps = ref([]);
const addDate = ref("");
const swap = ref({ off_date: "", makeup_date: "" });

const SOURCE = {
    rule: { label: "Theo luật", color: "primary" },
    compensatory: { label: "Nghỉ bù", color: "warning" },
    adjusted: { label: "HR thêm", color: "secondary" },
    swap: { label: "Hoán đổi", color: "info" },
    manual: { label: "Công ty", color: "secondary" },
};
const WEEKDAYS = ["CN", "T2", "T3", "T4", "T5", "T6", "T7"];

const parse = (iso) => {
    const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
    return new Date(y, m - 1, d);
};
const weekdayOf = (iso) => WEEKDAYS[parse(iso).getDay()];
const isWeekend = (iso) => [0, 6].includes(parse(iso).getDay());
function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = value.slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}

const rows = computed(() => {
    if (!group.value) return [];
    const existing = group.value.days.map((day) => ({
        key: `d-${day.id}`,
        kind: "existing",
        id: day.id,
        date: day.date,
        name: day.name,
        makeup_date: day.makeup_date,
        removed: removedIds.value.has(day.id),
        chip: removedIds.value.has(day.id)
            ? { label: "Sẽ bỏ", color: "error" }
            : SOURCE[day.source] ?? { label: day.source, color: "default" },
    }));
    const added = addedDates.value.map((date) => ({
        key: `a-${date}`,
        kind: "added",
        date,
        name: group.value.name,
        removed: false,
        chip: { label: "Mới thêm", color: "success" },
    }));
    const swapped = swaps.value.map((s) => ({
        key: `s-${s.off_date}`,
        kind: "swap",
        date: s.off_date,
        makeup_date: s.makeup_date,
        name: `Nghỉ hoán đổi ${group.value.name}`,
        removed: false,
        chip: { label: "Hoán đổi mới", color: "success" },
    }));
    return [...existing, ...added, ...swapped].sort((a, b) => a.date.localeCompare(b.date));
});

const changeCount = computed(() => removedIds.value.size + addedDates.value.length + swaps.value.length);
const dirty = computed(() => changeCount.value > 0);
const previewCount = computed(() => rows.value.filter((r) => !r.removed).length);

// Ngày đã có trong dịp (chưa bị bỏ) hoặc đã có trong nháp.
const takenDates = computed(() => new Set(rows.value.filter((r) => !r.removed).map((r) => r.date)));

function resetDraft() {
    removedIds.value = new Set();
    addedDates.value = [];
    swaps.value = [];
    addDate.value = "";
    swap.value = { off_date: "", makeup_date: "" };
    error.value = "";
}

async function load() {
    error.value = "";
    try {
        group.value = (await holidayService.showGroup(props.groupCode)).data;
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được dịp nghỉ.";
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open && props.groupCode) {
            group.value = null;
            resetDraft();
            load();
        }
    },
);

function toggleRow(row) {
    error.value = "";
    if (row.kind === "added") {
        addedDates.value = addedDates.value.filter((d) => d !== row.date);
    } else if (row.kind === "swap") {
        swaps.value = swaps.value.filter((s) => s.off_date !== row.date);
    } else {
        const next = new Set(removedIds.value);
        next.has(row.id) ? next.delete(row.id) : next.add(row.id);
        removedIds.value = next;
    }
}

function addDay() {
    error.value = "";
    const date = addDate.value.slice(0, 10);
    if (takenDates.value.has(date)) {
        error.value = `Ngày ${formatDate(date)} đã có trong lịch nghỉ.`;
        return;
    }
    addedDates.value = [...addedDates.value, date];
    addDate.value = "";
}

function addSwap() {
    error.value = "";
    const off = swap.value.off_date.slice(0, 10);
    const makeup = swap.value.makeup_date.slice(0, 10);
    if (isWeekend(off)) {
        error.value = "Ngày nghỉ hoán đổi phải là ngày làm việc (Thứ 2 – Thứ 6).";
        return;
    }
    if (!isWeekend(makeup)) {
        error.value = "Ngày làm bù phải là Thứ 7 hoặc Chủ nhật.";
        return;
    }
    if (takenDates.value.has(off)) {
        error.value = `Ngày ${formatDate(off)} đã có trong lịch nghỉ.`;
        return;
    }
    swaps.value = [...swaps.value, { off_date: off, makeup_date: makeup }];
    swap.value = { off_date: "", makeup_date: "" };
}

async function save() {
    saving.value = "apply";
    error.value = "";
    try {
        const response = await holidayService.applyChanges(props.groupCode, {
            remove_ids: [...removedIds.value],
            add_dates: addedDates.value,
            swaps: swaps.value,
        });
        if (response.data?.deleted) {
            toast.success("Đã bỏ toàn bộ ngày — dịp nghỉ đã bị xóa.");
        } else {
            toast.success(dirty.value ? "Đã lưu và xác nhận lịch nghỉ." : "Đã xác nhận lịch nghỉ.");
        }
        emit("changed");
        resetDraft();
        close();
    } catch (e) {
        const data = e.response?.data;
        error.value =
            Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? "Không lưu được thay đổi — lịch nghỉ giữ nguyên.";
    } finally {
        saving.value = "";
    }
}

async function unconfirm() {
    saving.value = "unconfirm";
    try {
        group.value = (await holidayService.confirmGroup(props.groupCode, false)).data;
        toast.success("Đã bỏ xác nhận.");
        emit("changed");
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không thực hiện được.";
    } finally {
        saving.value = "";
    }
}

function requestClose() {
    if (dirty.value) {
        discardDialog.value = true;
        return;
    }
    close();
}

function discardAndClose() {
    discardDialog.value = false;
    resetDraft();
    close();
}

function close() {
    emit("update:modelValue", false);
}
</script>

<style scoped>
.row-removed td {
    text-decoration: line-through;
    opacity: 0.55;
}
</style>
