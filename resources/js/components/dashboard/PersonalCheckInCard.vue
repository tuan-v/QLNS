<template>
    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
        <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-body-1 font-weight-bold">Hôm nay</div>
            <div class="text-h6 font-weight-bold font-tabular">{{ clock }}</div>
        </div>

        <div v-if="loadingToday" class="d-flex justify-center py-6">
            <v-progress-circular indeterminate size="24" />
        </div>

        <div v-else-if="!todayShifts.length" class="text-center py-6" style="opacity: 0.6">
            Hôm nay bạn không có ca làm việc nào.
        </div>

        <div v-else class="d-flex flex-column ga-3">
            <div
                v-for="entry in todayShifts"
                :key="entry.work_shift.id"
                class="border rounded-lg pa-3"
            >
                <div class="d-flex align-center justify-space-between mb-2">
                    <div>
                        <div class="font-weight-medium">{{ entry.work_shift.name }}</div>
                        <div class="text-caption" style="opacity: 0.65">
                            Ca: {{ entry.work_shift.start_time?.slice(0, 5) }} - {{ entry.work_shift.end_time?.slice(0, 5) }}
                        </div>
                    </div>
                </div>

                <div class="d-flex align-center justify-space-between text-body-2 py-1">
                    <span class="d-flex align-center ga-1" style="opacity: 0.75">
                        <v-icon
                            :icon="entry.attendance?.first_check_in_at ? 'mdi-check-circle' : 'mdi-circle-outline'"
                            :color="entry.attendance?.first_check_in_at ? 'success' : undefined"
                            size="16"
                        />
                        Check-in
                    </span>
                    <span class="font-weight-medium">{{ formatTime(entry.attendance?.first_check_in_at) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between text-body-2 py-1 mb-2">
                    <span class="d-flex align-center ga-1" style="opacity: 0.75">
                        <v-icon
                            :icon="entry.attendance?.last_check_out_at ? 'mdi-check-circle' : 'mdi-circle-outline'"
                            :color="entry.attendance?.last_check_out_at ? 'success' : undefined"
                            size="16"
                        />
                        Check-out
                    </span>
                    <span class="font-weight-medium">{{ formatTime(entry.attendance?.last_check_out_at) }}</span>
                </div>

                <v-btn
                    v-if="!entry.attendance?.first_check_in_at"
                    color="primary"
                    variant="flat"
                    block
                    :loading="submittingShiftId === entry.work_shift.id"
                    :disabled="submitting"
                    @click="submit(entry.work_shift.id, false)"
                >
                    Chấm công vào
                </v-btn>
                <v-btn
                    v-else-if="!entry.attendance?.last_check_out_at"
                    color="warning"
                    variant="flat"
                    block
                    :loading="submittingShiftId === entry.work_shift.id"
                    :disabled="submitting"
                    @click="submit(entry.work_shift.id, true)"
                >
                    Chấm công ra
                </v-btn>
                <div v-else class="text-caption text-center" style="opacity: 0.6">
                    Đã hoàn tất ca này.
                </div>
            </div>
        </div>

        <v-alert v-if="submitError" type="error" variant="tonal" density="compact" class="mt-3">
            {{ submitError }}
        </v-alert>

        <v-btn variant="text" size="small" class="mt-3" block :to="{ name: 'check-in' }">
            Xem đầy đủ trang Chấm công
        </v-btn>
    </v-sheet>
</template>

<script setup>
// Thẻ "Hôm nay" trên Dashboard cá nhân (2026-09-25, theo yêu cầu người dùng,
// kèm mockup cụ thể) — tái dùng THẲNG useCheckIn() (đã có sẵn ở trang Chấm
// công tự phục vụ, xem CheckInMobile.vue) thay vì viết lại logic GPS/IP/QR
// riêng ở đây — đúng tinh thần "1 nguồn logic" đã ghi ở đầu useCheckIn.js.
// Chỉ cherry-pick vài field cần cho 1 thẻ gọn (bỏ qua các dialog điều chỉnh/
// OT/bổ sung — không cần trên Dashboard, đã có nút "Xem đầy đủ" dẫn qua
// trang Chấm công thật nếu cần).
import { onBeforeUnmount, onMounted, ref } from "vue";
import { formatTime, useCheckIn } from "../../composables/useCheckIn";

const { todayShifts, loadingToday, submitting, submittingShiftId, submitError, submit } = useCheckIn();

const clock = ref(new Date().toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" }));
let clockTimer = null;

onMounted(() => {
    clockTimer = setInterval(() => {
        clock.value = new Date().toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
    }, 1000 * 30);
});

onBeforeUnmount(() => {
    clearInterval(clockTimer);
});
</script>

<style scoped>
.font-tabular {
    font-variant-numeric: tabular-nums;
}
</style>
