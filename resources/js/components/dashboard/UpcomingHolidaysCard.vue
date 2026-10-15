<template>
    <v-sheet class="border rounded-lg glass-panel pa-0 h-100 d-flex flex-column">
        <div class="d-flex align-center px-5 pt-4 pb-3">
            <v-icon size="18" color="primary" class="mr-2">mdi-calendar-star</v-icon>
            <span class="text-body-1 font-weight-bold">Ngày nghỉ lễ sắp tới</span>
            <v-spacer />
            <v-btn
                variant="text"
                color="primary"
                size="small"
                :to="{ name: 'holidays' }"
                append-icon="mdi-chevron-right"
                >Lịch nghỉ</v-btn
            >
        </div>
        <v-divider />

        <div v-if="holidays.length" class="flex-grow-1">
            <div
                v-for="holiday in holidays"
                :key="holiday.group_code"
                class="holiday-row px-5 py-3"
                role="link"
                tabindex="0"
                @click="router.push({ name: 'holidays' })"
                @keydown.enter="router.push({ name: 'holidays' })"
            >
                <div class="date-tile" :class="{ 'date-tile--soon': holiday.days_until <= 7 }">
                    <div class="date-tile__day">{{ day(holiday.start_date) }}</div>
                    <div class="date-tile__month">Th{{ month(holiday.start_date) }}</div>
                </div>
                <div class="min-width-0 flex-grow-1">
                    <div class="d-flex align-center ga-2">
                        <span class="text-body-2 font-weight-bold text-truncate">{{ holiday.name }}</span>
                        <v-chip v-if="!holiday.confirmed" size="x-small" color="warning" variant="tonal"
                            >Chưa xác nhận</v-chip
                        >
                    </div>
                    <div class="text-caption text-medium-emphasis">
                        {{ period(holiday) }} · nghỉ {{ holiday.total_days }} ngày<template v-if="!holiday.is_paid">
                            (không lương)</template
                        >
                    </div>
                    <div v-if="holiday.makeup_days.length" class="text-caption text-info">
                        Làm bù: {{ holiday.makeup_days.map((m) => `${m.weekday} ${shortDate(m.date)}`).join(", ") }}
                    </div>
                </div>
                <span class="until" :class="holiday.days_until <= 7 ? 'text-primary' : 'text-medium-emphasis'">
                    {{ holiday.days_until === 0 ? "Hôm nay" : `còn ${holiday.days_until} ngày` }}
                </span>
            </div>
        </div>
        <div v-else class="empty flex-grow-1">
            <v-icon icon="mdi-calendar-blank-outline" size="28" class="mb-1" />
            <div class="text-body-2">Chưa có ngày nghỉ lễ nào trong 4 tháng tới</div>
        </div>
    </v-sheet>
</template>

<script setup>
import { useRouter } from "vue-router";

// "Ngày nghỉ lễ sắp tới" — HolidayService::upcomingForDashboard(). Nhân viên chỉ thấy dịp
// HR đã xác nhận; người quản lý lịch nghỉ thấy cả dịp chưa xác nhận (chip cảnh báo).
defineProps({
    holidays: { type: Array, default: () => [] },
});

const router = useRouter();
const WEEKDAYS = ["CN", "T2", "T3", "T4", "T5", "T6", "T7"];

const toDate = (iso) => new Date(`${iso}T00:00:00`);
const day = (iso) => String(toDate(iso).getDate()).padStart(2, "0");
const month = (iso) => toDate(iso).getMonth() + 1;
const shortDate = (iso) => toDate(iso).toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit" });

function period(holiday) {
    const start = toDate(holiday.start_date);
    const startText = `${WEEKDAYS[start.getDay()]} ${shortDate(holiday.start_date)}`;
    if (holiday.start_date === holiday.end_date) {
        return startText;
    }
    const end = toDate(holiday.end_date);
    return `${startText} – ${WEEKDAYS[end.getDay()]} ${shortDate(holiday.end_date)}`;
}
</script>

<style scoped>
.holiday-row {
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: background 0.12s ease;
}
.holiday-row:hover,
.holiday-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.05);
    outline: none;
}
.holiday-row + .holiday-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.date-tile {
    width: 48px;
    flex-shrink: 0;
    text-align: center;
    border-radius: 10px;
    padding: 6px 0;
    border: 1px solid rgb(var(--v-theme-hairline));
    background: rgb(var(--v-theme-surface));
}
.date-tile--soon {
    border-color: rgba(var(--v-theme-primary), 0.4);
    background: rgba(var(--v-theme-primary), 0.06);
}
.date-tile__day {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.1;
    color: rgb(var(--v-theme-ink));
}
.date-tile__month {
    font-size: 11px;
    color: rgb(var(--v-theme-ink-muted));
}
.until {
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 16px;
    color: rgb(var(--v-theme-ink-muted));
}
</style>
