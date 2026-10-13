<template>
    <!-- Xem đầy đủ thư mời nhận việc trước khi duyệt: Admin Duyệt & gửi / Từ chối (kèm lý do) /
         Chỉnh sửa ngay tại đây (offer đang chờ duyệt). HR xem được và sửa offer đang chờ duyệt. -->
    <v-dialog :model-value="modelValue" max-width="640" scrollable @update:model-value="close">
        <v-card v-if="candidate && offer" rounded="xl">
            <v-card-title class="d-flex align-center ga-2 pt-5 px-6">
                <v-icon icon="mdi-email-search-outline" />
                <span class="flex-grow-1">Thư mời nhận việc</span>
                <StatusChip :status="offer.status" :map="OFFER_STATUS_MAP" />
            </v-card-title>
            <v-card-subtitle class="px-6" style="white-space: normal">
                Soạn bởi {{ offer.created_by ?? "—" }} lúc {{ formatDateTime(offer.created_at) }}
            </v-card-subtitle>

            <v-card-text class="px-6">
                <!-- Ứng viên -->
                <div class="section-title">Ứng viên</div>
                <div class="info-grid mb-4">
                    <span>Họ tên</span><strong>{{ candidate.full_name }}</strong>
                    <span>Email</span><span>{{ candidate.email }}</span>
                    <span v-if="candidate.phone">Điện thoại</span><span v-if="candidate.phone">{{ candidate.phone }}</span>
                    <span>Đợt tuyển</span><span>{{ opening?.title }}</span>
                    <template v-if="candidate.applied_position">
                        <span>Vị trí ứng tuyển</span><span>{{ candidate.applied_position.name }}</span>
                    </template>
                    <template v-if="candidate.desired_contract_type">
                        <span>Hình thức mong muốn</span><span>{{ CONTRACT_TYPE_LABELS[candidate.desired_contract_type] }}</span>
                    </template>
                </div>

                <!-- Nội dung offer -->
                <div class="section-title">Nội dung thư mời</div>
                <div class="info-grid mb-4">
                    <span>Phòng ban</span><strong>{{ offer.department ?? "—" }}</strong>
                    <span>Chức vụ</span><strong>{{ offer.position ?? "—" }}</strong>
                    <span>Loại hợp đồng</span><strong>{{ CONTRACT_TYPE_LABELS[offer.contract_type] ?? offer.contract_type }}</strong>
                    <span>Mức lương</span><strong class="text-primary">{{ money(offer.salary) }}/tháng</strong>
                    <span>Ngày bắt đầu</span><strong>{{ formatDate(offer.start_date) }}</strong>
                    <span>Hạn trả lời</span>
                    <strong :class="{ 'text-error': deadlinePassed }">
                        {{ formatDate(offer.response_deadline) }}<template v-if="deadlinePassed"> (đã qua — cần sửa hạn trước khi duyệt)</template>
                    </strong>
                    <span>Người liên hệ</span>
                    <span>
                        {{ offer.contact_name ?? "—" }}<template v-if="offer.contact_phone"> · {{ offer.contact_phone }}</template>
                        <template v-if="offer.contact_email"> · {{ offer.contact_email }}</template>
                    </span>
                </div>
                <template v-if="offer.message">
                    <div class="section-title">Lời nhắn gửi ứng viên</div>
                    <div class="message-box text-body-2 mb-4">{{ offer.message }}</div>
                </template>

                <!-- Kết quả duyệt / phản hồi -->
                <v-alert v-if="offer.status === 'rejected'" type="error" variant="tonal" density="compact" class="mb-3">
                    Không duyệt bởi {{ offer.reviewed_by ?? "Admin" }}: {{ offer.review_note }}
                </v-alert>
                <v-alert v-else-if="offer.reviewed_by && offer.status !== 'pending_approval'" type="success" variant="tonal" density="compact" class="mb-3">
                    Duyệt bởi {{ offer.reviewed_by }} lúc {{ formatDateTime(offer.reviewed_at) }}
                </v-alert>
                <v-alert v-if="['accepted', 'declined'].includes(offer.status)" :type="offer.status === 'accepted' ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-3">
                    Ứng viên {{ offer.status === "accepted" ? "đã chấp nhận" : "đã từ chối" }}
                    <template v-if="offer.response_recorded_by"> (HR ghi nhận: {{ offer.response_recorded_by }})</template>
                    <template v-if="offer.response_note">. {{ offer.response_note }}</template>
                </v-alert>

                <!-- Từ chối: nhập lý do -->
                <v-expand-transition>
                    <div v-if="rejecting">
                        <v-textarea
                            v-model="rejectNote"
                            label="Lý do không duyệt (HR sẽ thấy)"
                            rows="2"
                            auto-grow
                            variant="outlined"
                            autofocus
                            :error-messages="rejectError"
                        />
                    </div>
                </v-expand-transition>
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-2">{{ error }}</v-alert>
                <div v-if="canReview && !rejecting" class="text-caption mt-1" style="opacity: 0.7">
                    Duyệt xong hệ thống gửi email thư mời (không có link) cho ứng viên kèm thông tin người liên hệ.
                </div>
            </v-card-text>

            <v-card-actions class="px-6 pb-5 flex-wrap ga-2">
                <v-btn v-if="canEdit && !rejecting" variant="tonal" prepend-icon="mdi-pencil-outline" @click="editOpen = true">
                    Chỉnh sửa
                </v-btn>
                <v-spacer />
                <template v-if="rejecting">
                    <v-btn variant="text" @click="rejecting = false">Quay lại</v-btn>
                    <v-btn color="error" variant="flat" :loading="busy === 'reject'" @click="reject">Xác nhận không duyệt</v-btn>
                </template>
                <template v-else-if="canReview">
                    <v-btn color="error" variant="tonal" prepend-icon="mdi-close" @click="rejecting = true">Không duyệt</v-btn>
                    <v-btn color="success" variant="flat" prepend-icon="mdi-send-check-outline" :disabled="deadlinePassed" :loading="busy === 'approve'" @click="approve">
                        Duyệt & gửi ứng viên
                    </v-btn>
                </template>
                <v-btn v-else variant="text" @click="close(false)">Đóng</v-btn>
            </v-card-actions>
        </v-card>

        <OfferDialog
            v-model="editOpen"
            :candidate="candidate"
            :offer="offer"
            :opening="opening"
            :department-options="departmentOptions"
            @saved="onEdited"
        />
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import StatusChip from "../../components/common/StatusChip.vue";
import OfferDialog from "./OfferDialog.vue";
import { CONTRACT_TYPE_LABELS, OFFER_STATUS_MAP } from "../../composables/recruitmentStatus";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // Ứng viên (dòng trong bảng) — offer = candidate.offer (offer gần nhất).
    candidate: { type: Object, default: null },
    opening: { type: Object, default: null },
    departmentOptions: { type: Array, default: () => [] },
    canApprove: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "changed"]);

const toast = useToastStore();
const rejecting = ref(false);
const rejectNote = ref("");
const rejectError = ref("");
const error = ref("");
const busy = ref("");
const editOpen = ref(false);

const offer = computed(() => props.candidate?.offer ?? null);
const pending = computed(() => offer.value?.status === "pending_approval");
const canReview = computed(() => props.canApprove && pending.value);
const canEdit = computed(() => (props.canApprove || props.canManage) && pending.value);
const deadlinePassed = computed(() => !!offer.value && offer.value.response_deadline < new Date().toISOString().slice(0, 10));

function formatDate(value) {
    if (!value) return "—";
    const [y, m, d] = String(value).slice(0, 10).split("-");
    return `${d}/${m}/${y}`;
}

function formatDateTime(value) {
    if (!value) return "—";
    return new Date(value).toLocaleString("vi-VN", { hour: "2-digit", minute: "2-digit", day: "2-digit", month: "2-digit", year: "numeric" });
}

function money(value) {
    return `${Math.round(value ?? 0).toLocaleString("vi-VN")} ₫`;
}

function firstError(e) {
    const errors = e.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message;
}

function close(value = false) {
    emit("update:modelValue", value);
}

async function approve() {
    busy.value = "approve";
    error.value = "";
    try {
        await recruitmentService.reviewOffer(offer.value.id, { status: "approved" });
        toast.success(`Đã duyệt và gửi thư mời cho ${props.candidate.full_name}.`);
        emit("changed");
        close(false);
    } catch (e) {
        error.value = firstError(e) ?? "Không duyệt được offer.";
    } finally {
        busy.value = "";
    }
}

async function reject() {
    if (!rejectNote.value.trim()) {
        rejectError.value = "Vui lòng nhập lý do để HR soạn lại";
        return;
    }
    busy.value = "reject";
    rejectError.value = "";
    error.value = "";
    try {
        await recruitmentService.reviewOffer(offer.value.id, { status: "rejected", review_note: rejectNote.value.trim() });
        toast.success("Đã trả offer về cho HR.");
        emit("changed");
        close(false);
    } catch (e) {
        const errs = e.response?.data?.errors;
        if (errs?.review_note) rejectError.value = errs.review_note[0];
        else error.value = firstError(e) ?? "Không cập nhật được offer.";
    } finally {
        busy.value = "";
    }
}

// Sửa xong -> trang cha tải lại, dialog vẫn mở với nội dung mới để duyệt tiếp.
function onEdited() {
    toast.success("Đã lưu thay đổi offer.");
    emit("changed");
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        rejecting.value = false;
        rejectNote.value = "";
        rejectError.value = "";
        error.value = "";
    },
);
</script>

<style scoped>
.section-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.6;
    margin-bottom: 6px;
}
.info-grid {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 6px 12px;
    font-size: 0.875rem;
}
.info-grid > span:nth-child(odd) {
    opacity: 0.7;
}
.message-box {
    white-space: pre-line;
    background: rgba(var(--v-theme-on-surface), 0.05);
    border-radius: 8px;
    padding: 10px 12px;
}
@media (max-width: 480px) {
    .info-grid {
        grid-template-columns: 1fr;
        gap: 2px;
    }
    .info-grid > span:nth-child(odd) {
        margin-top: 6px;
    }
}
</style>
