<template>
    <div>
        <div v-if="loading && !data" class="d-flex justify-center py-8"><v-progress-circular indeterminate /></div>

        <v-alert v-else-if="noProfile" type="info" variant="tonal" icon="mdi-account-question-outline">
            Tài khoản của bạn chưa gắn với hồ sơ nhân viên nên chưa có phụ cấp. Liên hệ HR nếu cần.
        </v-alert>
        <v-alert v-else-if="error" type="error" variant="tonal" density="compact">{{ error }}</v-alert>

        <template v-else-if="data">
            <!-- Ước tính tháng này — cùng cách tính với HR (AllowanceService::monthEstimate()) -->
            <v-sheet class="estimate rounded-lg pa-4 mb-4" color="transparent">
                <div class="d-flex flex-wrap align-center ga-4">
                    <span class="estimate__icon"><v-icon icon="mdi-hand-coin-outline" size="24" /></span>
                    <div class="flex-grow-1">
                        <div class="text-caption text-medium-emphasis">
                            Phụ cấp ước tính {{ monthLabel }} (tính tới hôm nay)
                        </div>
                        <div class="text-h5 font-weight-bold">{{ money(data.estimate.amount) }}</div>
                        <div v-if="hasDaily" class="text-body-2">
                            {{ data.estimate.office_days }} ngày làm tại công ty đã được duyệt
                            <span v-if="data.estimate.pending_days" class="text-warning">
                                · +{{ data.estimate.pending_days }} ngày đang chờ HR duyệt (thêm khoảng
                                {{ money(data.estimate.pending_amount) }})
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-caption text-medium-emphasis mt-2">
                    Số chính thức ghi trên phiếu lương cuối tháng.
                    <router-link :to="{ name: 'my-profile', query: { tab: 'payslips' } }">Xem phiếu lương</router-link>
                </div>
            </v-sheet>

            <div class="text-subtitle-1 font-weight-bold mb-2">Các khoản bạn đang được hưởng</div>
            <div v-if="!data.items.length" class="empty text-body-2">
                Bạn chưa được hưởng khoản phụ cấp, trợ cấp nào. Liên hệ HR nếu bạn nghĩ đây là nhầm lẫn.
            </div>
            <div v-else class="items">
                <v-sheet v-for="item in data.items" :key="item.name" class="border rounded-lg pa-4" color="transparent">
                    <div class="d-flex align-center justify-space-between ga-2">
                        <div class="font-weight-bold">{{ item.name }}</div>
                        <v-chip size="x-small" variant="tonal" label>{{ item.type_label }}</v-chip>
                    </div>
                    <div class="text-h6 font-weight-bold mt-1">
                        {{ money(item.amount)
                        }}<span class="text-body-2 text-medium-emphasis">{{
                            item.unit === "day" ? " / ngày làm tại công ty" : " / tháng"
                        }}</span>
                    </div>
                    <div v-if="item.note" class="text-body-2 mt-1">Nội dung: {{ item.note }}</div>
                    <div v-if="item.description" class="text-caption text-medium-emphasis mt-1">
                        {{ item.description }}
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">
                        {{
                            item.effective_from
                                ? `Áp dụng từ ${formatDate(item.effective_from)}`
                                : "Mọi nhân viên đều được hưởng"
                        }}
                    </div>
                </v-sheet>
            </div>

            <v-alert
                v-if="hasDaily"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-4"
                icon="mdi-information-outline"
            >
                Phụ cấp ăn trưa chỉ tính những ngày bạn chọn <strong>"Tại công ty"</strong> khi chấm công vào và đã được
                duyệt — ngày làm online không tính, làm nửa ngày vẫn được 1 suất. Chọn nhầm thì báo HR sửa.
            </v-alert>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from "vue";
import allowanceService from "../../services/allowanceService";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Tab "Phụ cấp" ở Hồ sơ của tôi (2026-10-22): nhân viên xem các khoản phụ cấp / trợ cấp đang hưởng + ước tính tháng
// này. Chỉ đọc — HR đặt ở Chi tiết nhân viên → tab Phụ cấp. API GET /employees/me/allowances (chỉ dữ liệu của mình).
const data = ref(null);
const loading = ref(true);
const error = ref("");
const noProfile = ref(false);

const monthLabel = `tháng ${new Date().getMonth() + 1}/${new Date().getFullYear()}`;
const money = (value) => `${Math.round(Number(value) || 0).toLocaleString("vi-VN")}đ`;
const formatDate = (value) => new Date(value).toLocaleDateString("vi-VN");
const hasDaily = computed(() => data.value?.items.some((item) => item.unit === "day") ?? false);

async function load() {
    error.value = "";
    try {
        data.value = (await allowanceService.mine()).data;
    } catch (e) {
        noProfile.value = e.response?.status === 404;
        error.value = e.response?.data?.message ?? "Không tải được phụ cấp.";
    } finally {
        loading.value = false;
    }
}

// Phụ cấp của mình đổi (HR/Admin duyệt) hoặc chấm công của mình đổi (vào/ra, được duyệt) -> tải lại ngay.
useRealtimeRefresh(load, { mine: ["allowances", "attendance"] });
onMounted(load);
</script>

<style scoped>
.estimate {
    border: 1px solid rgba(var(--v-theme-primary), 0.25);
    background: rgba(var(--v-theme-primary), 0.06) !important;
}
.estimate__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border-radius: 12px;
    color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.12);
}
.items {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.empty {
    padding: 24px;
    text-align: center;
    opacity: 0.7;
    border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 12px;
}
</style>
