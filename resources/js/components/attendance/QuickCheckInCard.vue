<template>
    <!-- Chấm công một chạm: chỉ hiện ĐÚNG việc nên làm ngay theo giờ hiện tại
         (xem quickAction trong useCheckIn.js). Các thẻ từng ca bên dưới vẫn giữ nguyên. -->
    <v-sheet
        v-if="action"
        class="quick-card rounded-lg pa-5 mb-4 d-flex flex-wrap align-center ga-4"
        :class="`quick-card--${tone}`"
    >
        <v-avatar :color="tone" variant="tonal" size="52">
            <v-icon size="28">{{ icon }}</v-icon>
        </v-avatar>

        <div class="flex-grow-1" style="min-width: 220px">
            <div class="text-subtitle-1 font-weight-bold">{{ heading }}</div>
            <div class="text-body-2" style="opacity: 0.8">
                {{ action.entry.work_shift.name }} ·
                {{ hhmm(action.entry.work_shift.start_time) }} –
                {{ hhmm(action.entry.work_shift.end_time) }}
                <template v-if="action.type === 'out'">
                    · vào lúc
                    {{ formatTime(action.entry.attendance.first_check_in_at) }}
                </template>
            </div>
            <div v-if="hint" class="text-caption mt-1" :class="`text-${tone}`">
                {{ hint }}
            </div>
        </div>

        <v-btn
            v-if="action.type !== 'upcoming'"
            :color="action.type === 'out' ? 'warning' : 'primary'"
            size="x-large"
            variant="flat"
            rounded="lg"
            :block="block"
            :prepend-icon="action.type === 'out' ? 'mdi-logout' : 'mdi-login'"
            :loading="
                submitting && submittingShiftId === action.entry.work_shift.id
            "
            :disabled="submitting"
            @click="
                $emit(
                    'submit',
                    action.entry.work_shift.id,
                    action.type === 'out',
                )
            "
        >
            {{ action.type === "out" ? "Chấm công ra" : "Chấm công vào" }}
        </v-btn>
    </v-sheet>
</template>

<script setup>
import { computed } from "vue";
import { formatTime } from "../../composables/useCheckIn";

const props = defineProps({
    action: { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    submittingShiftId: { type: [Number, String], default: null },
    // Mobile: nút chiếm cả chiều ngang cho dễ bấm.
    block: { type: Boolean, default: false },
});
defineEmits(["submit"]);

const hhmm = (time) => String(time ?? "").slice(0, 5);

const tone = computed(() => {
    if (props.action?.type === "out")
        return props.action.overdue ? "error" : "warning";
    if (props.action?.type === "in")
        return props.action.late ? "warning" : "primary";
    return "info";
});

const icon = computed(
    () =>
        ({
            out: "mdi-timer-sand",
            in: "mdi-fingerprint",
            upcoming: "mdi-clock-outline",
        })[props.action?.type],
);

const heading = computed(() => {
    const a = props.action;
    if (!a) return "";
    if (a.type === "out")
        return a.overdue ? "Bạn chưa chấm công ra" : "Đang trong ca";
    if (a.type === "in")
        return a.late ? "Đã tới giờ ca — chấm công vào ngay" : "Sắp vào ca";
    return "Ca tiếp theo hôm nay";
});

const hint = computed(() => {
    const a = props.action;
    if (!a) return "";
    if (a.type === "out" && a.overdue) {
        return `Ca đã kết thúc lúc ${hhmm(a.entry.work_shift.end_time)} — chấm ra ngay, hoặc dùng "Xin điều chỉnh" nếu đã về từ trước.`;
    }
    if (a.type === "upcoming") {
        return `Mở chấm công lúc ${a.opensAt.toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" })}.`;
    }
    return "";
});
</script>

<style scoped>
.quick-card {
    border: 1px solid rgba(var(--v-theme-primary), 0.25);
    background: rgba(var(--v-theme-primary), 0.05);
}
.quick-card--warning {
    border-color: rgba(var(--v-theme-warning), 0.45);
    background: rgba(var(--v-theme-warning), 0.07);
}
.quick-card--error {
    border-color: rgba(var(--v-theme-error), 0.45);
    background: rgba(var(--v-theme-error), 0.06);
}
.quick-card--info {
    border-color: rgba(var(--v-theme-info), 0.35);
    background: rgba(var(--v-theme-info), 0.05);
}
</style>
