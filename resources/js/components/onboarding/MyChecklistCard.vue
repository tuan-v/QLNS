<template>
    <!-- Checklist nhận việc/nghỉ việc ĐANG LÀM của chính mình (Tổng quan, phần "Của tôi").
         Không có checklist nào đang làm thì không hiện gì. -->
    <v-sheet
        v-for="checklist in active"
        :key="checklist.id"
        class="border rounded-lg glass-panel pa-4 mb-4"
        color="transparent"
    >
        <div class="d-flex flex-wrap align-center ga-3 mb-2">
            <v-icon
                :icon="checklist.type === 'onboarding' ? 'mdi-account-plus-outline' : 'mdi-account-arrow-right-outline'"
                color="primary"
            />
            <div class="flex-grow-1">
                <div class="text-subtitle-1 font-weight-bold">
                    {{ checklist.type === "onboarding" ? "Việc cần làm khi nhận việc" : "Việc cần làm trước khi nghỉ việc" }}
                </div>
                <div class="text-caption" style="opacity: 0.7">
                    {{ checklist.type === "onboarding" ? "Ngày vào làm" : "Ngày làm việc cuối" }}:
                    {{ formatDate(checklist.reference_date) }} · Bạn đánh dấu được các việc phần "Nhân viên".
                </div>
            </div>
            <div class="text-right" style="min-width: 140px">
                <div class="text-body-2 font-weight-bold">{{ checklist.progress.percent }}% hoàn thành</div>
                <v-progress-linear :model-value="checklist.progress.percent" color="primary" height="6" rounded class="mt-1" />
            </div>
        </div>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-2">{{ error }}</v-alert>
        <ChecklistItemList :items="checklist.items" :busy-item-id="busyItemId" @toggle="toggle" />
    </v-sheet>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import onboardingService from "../../services/onboardingService";
import ChecklistItemList from "./ChecklistItemList.vue";
import { formatDate } from "../../composables/onboardingLabels";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const checklists = ref([]);
const busyItemId = ref(null);
const error = ref("");

const active = computed(() => checklists.value.filter((c) => c.status === "in_progress"));

async function load() {
    try {
        checklists.value = (await onboardingService.mine()).data.data;
    } catch {
        // Tài khoản chưa gắn hồ sơ nhân viên -> API trả rỗng; lỗi khác thì bỏ qua, không chặn Tổng quan.
        checklists.value = [];
    }
}

async function toggle(item, done) {
    busyItemId.value = item.id;
    error.value = "";
    try {
        const updated = (await onboardingService.updateItem(item.id, { done })).data.data;
        checklists.value = checklists.value.map((c) => (c.id === updated.id ? updated : c));
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không cập nhật được việc này.";
    } finally {
        busyItemId.value = null;
    }
}

useRealtimeRefresh(load, { mine: ["onboarding"] });
onMounted(load);
</script>
