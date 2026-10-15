<template>
    <v-dialog :model-value="modelValue" max-width="720" scrollable :fullscreen="mobile" @update:model-value="close">
        <v-card :rounded="mobile ? 0 : 'xl'">
            <div class="d-flex align-start ga-3 px-6 pt-5 pb-4">
                <v-avatar color="primary" variant="tonal" rounded="lg" size="44">
                    <v-icon icon="mdi-calendar-star-outline" />
                </v-avatar>
                <div class="flex-grow-1 min-width-0">
                    <div class="text-caption text-medium-emphasis">Số dư phép năm {{ year }}</div>
                    <div class="text-h6 font-weight-bold">{{ employee?.full_name ?? "Của tôi" }}</div>
                </div>
                <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
            </div>
            <v-divider />

            <v-card-text class="px-6 py-5">
                <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>

                <section v-for="item in balances" :key="item.leave_type.id" class="mb-6">
                    <div class="d-flex align-center mb-3">
                        <span class="text-subtitle-1 font-weight-bold">{{ item.leave_type.name }}</span>
                        <v-spacer />
                        <span class="remaining" :class="item.remaining_days < 0 ? 'text-error' : 'text-success'">
                            Còn {{ days(item.remaining_days) }} ngày
                        </span>
                    </div>

                    <!-- Cách tính số dư -->
                    <div class="breakdown">
                        <div class="breakdown__row">
                            <span>Được cấp năm {{ year }}</span
                            ><span>+{{ days(item.allocated_days) }}</span>
                        </div>
                        <div v-if="item.carried_forward_days" class="breakdown__row">
                            <span>
                                Chuyển từ năm {{ year - 1 }}
                                <span
                                    v-if="item.carry_expiry"
                                    class="text-caption"
                                    :class="item.carry_expired ? 'text-error' : 'text-warning'"
                                >
                                    ·
                                    {{
                                        item.carry_expired
                                            ? "đã hết hạn"
                                            : `dùng trước ${formatDate(item.carry_expiry)}`
                                    }}
                                </span>
                            </span>
                            <span>+{{ days(item.carried_forward_days) }}</span>
                        </div>
                        <div v-if="item.adjusted_days" class="breakdown__row">
                            <span>Điều chỉnh (xem lịch sử)</span>
                            <span>{{ item.adjusted_days > 0 ? "+" : "" }}{{ days(item.adjusted_days) }}</span>
                        </div>
                        <div class="breakdown__row">
                            <span>Đã nghỉ (đơn đã duyệt)</span><span>−{{ days(item.used_days) }}</span>
                        </div>
                        <div class="breakdown__row breakdown__row--total">
                            <span>Còn lại</span><span>{{ days(item.remaining_days) }}</span>
                        </div>
                    </div>

                    <!-- HR: cộng / trừ tay -->
                    <div v-if="canAdjust && adjustingTypeId === item.leave_type.id" class="adjust-panel mt-3">
                        <div class="font-weight-medium mb-3">Điều chỉnh số dư</div>
                        <div class="d-flex flex-wrap ga-3 align-start">
                            <v-btn-toggle
                                v-model="form.sign"
                                mandatory
                                color="primary"
                                variant="outlined"
                                density="comfortable"
                                divided
                            >
                                <v-btn value="+" prepend-icon="mdi-plus">Cộng</v-btn>
                                <v-btn value="-" prepend-icon="mdi-minus">Trừ</v-btn>
                            </v-btn-toggle>
                            <v-text-field
                                v-model.number="form.days"
                                type="number"
                                min="0.5"
                                step="0.5"
                                suffix="ngày"
                                density="comfortable"
                                style="max-width: 140px"
                                :error-messages="errors.days"
                            />
                        </div>
                        <v-textarea
                            v-model="form.reason"
                            rows="2"
                            auto-grow
                            density="comfortable"
                            placeholder="Lý do (bắt buộc) — vd: nhập số dư ban đầu từ Excel, thưởng phép dự án, sửa sai..."
                            :error-messages="errors.reason"
                            class="mt-2"
                        />
                        <div class="text-caption text-medium-emphasis">Nhân viên sẽ nhận thông báo kèm lý do.</div>
                        <div class="d-flex justify-end ga-2 mt-3">
                            <v-btn variant="text" :disabled="saving" @click="adjustingTypeId = null">Thôi</v-btn>
                            <v-btn
                                color="primary"
                                :loading="saving"
                                :disabled="!form.days || !form.reason.trim()"
                                @click="submit(item)"
                            >
                                Lưu điều chỉnh
                            </v-btn>
                        </div>
                    </div>
                    <v-btn
                        v-else-if="canAdjust"
                        variant="text"
                        color="primary"
                        size="small"
                        prepend-icon="mdi-plus-minus-variant"
                        class="mt-2 px-1"
                        @click="startAdjust(item)"
                    >
                        Điều chỉnh số dư
                    </v-btn>

                    <!-- Lịch sử -->
                    <div v-if="item.history.length" class="mt-4">
                        <div class="section-label">Lịch sử thay đổi</div>
                        <div v-for="entry in item.history" :key="entry.id" class="history-row">
                            <v-avatar :color="TYPE_META[entry.type]?.color" variant="tonal" size="28">
                                <v-icon :icon="TYPE_META[entry.type]?.icon" size="15" />
                            </v-avatar>
                            <div class="flex-grow-1 min-width-0">
                                <div class="text-body-2">
                                    <span class="font-weight-medium">{{ TYPE_META[entry.type]?.label }}</span>
                                    <span class="text-medium-emphasis">
                                        · {{ entry.creator ?? "Hệ thống" }} · {{ formatDate(entry.created_at) }}</span
                                    >
                                </div>
                                <div v-if="entry.reason" class="text-caption text-medium-emphasis">
                                    {{ entry.reason }}
                                </div>
                            </div>
                            <span class="font-weight-bold" :class="entry.days > 0 ? 'text-success' : 'text-error'">
                                {{ entry.days > 0 ? "+" : "" }}{{ days(entry.days) }}
                            </span>
                        </div>
                    </div>
                </section>

                <div v-if="!loading && !balances.length" class="text-center text-medium-emphasis py-6">
                    Không có loại phép nào có quỹ theo năm.
                </div>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
import { useDisplay } from "vuetify";
import leaveBalanceService from "../../services/leaveBalanceService";
import { useToastStore } from "../../stores/useToastStore";

// Chi tiết số dư phép + lịch sử thay đổi (2026-10-15). HR (canAdjust) cộng/trừ được kèm lý do;
// nhân viên mở ở chế độ chỉ xem (employee = null -> dữ liệu của chính mình).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    employee: { type: Object, default: null },
    year: { type: Number, default: () => new Date().getFullYear() },
    canAdjust: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "changed"]);

const { mobile } = useDisplay();
const toast = useToastStore();
const balances = ref([]);
const loading = ref(false);
const error = ref("");
const saving = ref(false);
const adjustingTypeId = ref(null);
const errors = ref({});
const form = reactive({ sign: "+", days: 1, reason: "" });

const TYPE_META = {
    manual: { label: "HR điều chỉnh", icon: "mdi-account-edit-outline", color: "primary" },
    carry_forward: { label: "Chuyển từ năm trước", icon: "mdi-arrow-right-bold-outline", color: "info" },
    carry_expired: { label: "Phép chuyển hết hạn", icon: "mdi-timer-off-outline", color: "error" },
};

const days = (value) =>
    new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(Math.abs(value ?? 0) === 0 ? 0 : value);
const formatDate = (value) =>
    value ? new Date(value.length === 10 ? `${value}T00:00:00` : value).toLocaleDateString("vi-VN") : "";

async function load() {
    loading.value = true;
    error.value = "";
    try {
        const response = props.employee
            ? await leaveBalanceService.detail(props.employee.id, props.year)
            : await leaveBalanceService.mine(props.year);
        balances.value = response.data.data;
    } catch (e) {
        balances.value = [];
        error.value = e.response?.data?.message ?? "Không tải được số dư phép.";
    } finally {
        loading.value = false;
    }
}

function startAdjust(item) {
    adjustingTypeId.value = item.leave_type.id;
    Object.assign(form, { sign: "+", days: 1, reason: "" });
    errors.value = {};
}

async function submit(item) {
    saving.value = true;
    errors.value = {};
    try {
        const response = await leaveBalanceService.adjust(props.employee.id, {
            leave_type_id: item.leave_type.id,
            year: props.year,
            days: form.sign === "-" ? -Math.abs(form.days) : Math.abs(form.days),
            reason: form.reason.trim(),
        });
        balances.value = response.data.data;
        adjustingTypeId.value = null;
        toast.success("Đã điều chỉnh số dư phép.");
        emit("changed");
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
        } else {
            toast.error(e.response?.data?.message ?? "Không điều chỉnh được.");
        }
    } finally {
        saving.value = false;
    }
}

function close() {
    emit("update:modelValue", false);
}

watch(
    () => [props.modelValue, props.employee?.id, props.year],
    ([open]) => {
        if (open) {
            adjustingTypeId.value = null;
            load();
        }
    },
    { immediate: true },
);
</script>

<style scoped>
.remaining {
    font-size: 1.125rem;
    font-weight: 700;
}
.breakdown {
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
    overflow: hidden;
}
.breakdown__row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 14px;
    font-size: 0.875rem;
    font-variant-numeric: tabular-nums;
}
.breakdown__row + .breakdown__row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.breakdown__row--total {
    font-weight: 700;
    background: rgba(var(--v-theme-on-surface), 0.03);
}
.adjust-panel {
    padding: 16px;
    border-radius: 12px;
    border: 1px solid rgba(var(--v-theme-primary), 0.3);
    background: rgba(var(--v-theme-primary), 0.04);
}
.section-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 8px;
}
.history-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
}
.history-row + .history-row {
    border-top: 1px dashed rgb(var(--v-theme-hairline));
}
</style>
