<template>
    <!-- Trang công khai cho ứng viên (không đăng nhập) — mở từ link trong email thư mời
         nhận việc. Mã trong URL là khóa duy nhất; xem RecruitmentOfferService. -->
    <div class="offer-page d-flex align-center justify-center pa-4">
        <v-card class="offer-card glass-panel" rounded="xl" max-width="560" width="100%">
            <div v-if="loading" class="d-flex justify-center py-12">
                <v-progress-circular indeterminate />
            </div>

            <v-card-text v-else-if="loadError" class="pa-8 text-center">
                <v-icon icon="mdi-link-variant-off" size="48" color="error" class="mb-3" />
                <div class="text-h6 font-weight-bold mb-2">Không mở được thư mời</div>
                <div style="opacity: 0.75">{{ loadError }}</div>
            </v-card-text>

            <v-card-text v-else-if="offer" class="pa-8">
                <div class="text-caption text-uppercase font-weight-bold" style="opacity: 0.6">{{ offer.company }}</div>
                <h1 class="text-h5 font-weight-bold mb-1">Thư mời nhận việc</h1>
                <p class="mb-5" style="opacity: 0.8">
                    Xin chào <strong>{{ offer.candidate_name }}</strong
                    >, chúng tôi trân trọng mời bạn nhận vị trí <strong>{{ offer.title }}</strong
                    >.
                </p>

                <div class="offer-grid mb-5">
                    <div v-if="offer.department" class="offer-label">Phòng ban</div>
                    <div v-if="offer.department">
                        {{ offer.department }}<template v-if="offer.position"> · {{ offer.position }}</template>
                    </div>
                    <div class="offer-label">Loại hợp đồng</div>
                    <div>{{ CONTRACT_TYPE_LABELS[offer.contract_type] ?? offer.contract_type }}</div>
                    <div class="offer-label">Mức lương</div>
                    <div class="font-weight-bold">{{ Number(offer.salary).toLocaleString("vi-VN") }} ₫/tháng</div>
                    <div class="offer-label">Ngày bắt đầu</div>
                    <div class="font-weight-bold">{{ formatDate(offer.start_date) }}</div>
                    <div class="offer-label">Hạn trả lời</div>
                    <div>{{ formatDate(offer.response_deadline) }}</div>
                </div>

                <v-sheet
                    v-if="offer.message"
                    class="pa-4 mb-5 rounded-lg"
                    color="primary"
                    variant="tonal"
                    style="white-space: pre-line"
                >
                    {{ offer.message }}
                </v-sheet>

                <!-- Đã trả lời / hết hạn / không còn hiệu lực -->
                <v-alert
                    v-if="!offer.can_respond"
                    :type="offer.status === 'accepted' ? 'success' : offer.status === 'declined' ? 'info' : 'warning'"
                    variant="tonal"
                >
                    <template v-if="justResponded && offer.status === 'accepted'">
                        Cảm ơn bạn đã chấp nhận! Phòng Nhân sự sẽ liên hệ để hướng dẫn thủ tục nhận việc.
                    </template>
                    <template v-else-if="justResponded && offer.status === 'declined'">
                        Cảm ơn bạn đã phản hồi. Chúc bạn nhiều thành công!
                    </template>
                    <template v-else>{{ offer.closed_message }}</template>
                </v-alert>

                <template v-else>
                    <v-alert v-if="respondError" type="error" variant="tonal" density="compact" class="mb-3">{{
                        respondError
                    }}</v-alert>

                    <v-expand-transition>
                        <v-textarea
                            v-if="decliningMode"
                            v-model="note"
                            label="Lý do từ chối (không bắt buộc)"
                            rows="2"
                            auto-grow
                            variant="outlined"
                            class="mb-2"
                        />
                    </v-expand-transition>

                    <div class="d-flex flex-wrap ga-3">
                        <template v-if="!decliningMode">
                            <v-btn
                                color="success"
                                size="large"
                                variant="flat"
                                prepend-icon="mdi-check"
                                :loading="submitting === 'accept'"
                                :disabled="!!submitting"
                                @click="respond('accept')"
                            >
                                Chấp nhận
                            </v-btn>
                            <v-btn
                                size="large"
                                variant="outlined"
                                :disabled="!!submitting"
                                @click="decliningMode = true"
                            >
                                Từ chối
                            </v-btn>
                        </template>
                        <template v-else>
                            <v-btn
                                color="error"
                                size="large"
                                variant="flat"
                                :loading="submitting === 'decline'"
                                @click="respond('decline')"
                            >
                                Xác nhận từ chối
                            </v-btn>
                            <v-btn size="large" variant="text" :disabled="!!submitting" @click="decliningMode = false"
                                >Quay lại</v-btn
                            >
                        </template>
                    </div>
                </template>
            </v-card-text>
        </v-card>
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import recruitmentService from "../../services/recruitmentService";
import { CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";

const route = useRoute();

const offer = ref(null);
const loading = ref(true);
const loadError = ref("");
const decliningMode = ref(false);
const note = ref("");
const submitting = ref(null);
const respondError = ref("");
const justResponded = ref(false);

function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = String(value).slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}

async function load() {
    loading.value = true;
    try {
        offer.value = (await recruitmentService.publicOffer(route.params.token)).data.data;
    } catch (e) {
        loadError.value =
            e.response?.status === 429
                ? "Bạn thao tác quá nhanh, vui lòng thử lại sau 1 phút."
                : (e.response?.data?.message ?? "Link không hợp lệ hoặc thư mời đã bị thu hồi.");
    } finally {
        loading.value = false;
    }
}

async function respond(decision) {
    submitting.value = decision;
    respondError.value = "";
    try {
        offer.value = (
            await recruitmentService.respondOffer(route.params.token, {
                decision,
                note: decision === "decline" ? note.value.trim() || null : null,
            })
        ).data.data;
        justResponded.value = true;
    } catch (e) {
        const errors = e.response?.data?.errors;
        respondError.value = errors
            ? Object.values(errors)[0]?.[0]
            : (e.response?.data?.message ?? "Không gửi được phản hồi, vui lòng thử lại.");
        // Có thể đã hết hạn / bị rút trong lúc mở trang -> tải lại trạng thái mới nhất.
        if (e.response?.status === 422 || e.response?.status === 404) await load();
    } finally {
        submitting.value = null;
    }
}

onMounted(load);
</script>

<style scoped>
.offer-page {
    min-height: 100vh;
    background: rgb(var(--v-theme-background));
}
.offer-grid {
    display: grid;
    grid-template-columns: 140px 1fr;
    row-gap: 10px;
}
.offer-label {
    opacity: 0.65;
}
@media (max-width: 480px) {
    .offer-grid {
        grid-template-columns: 1fr;
        row-gap: 2px;
    }
    .offer-grid > :nth-child(even) {
        margin-bottom: 8px;
    }
}
</style>
