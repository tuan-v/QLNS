<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-cog-outline"
        size="sm"
        title="Chính sách chuyển phép"
        subtitle="Phép năm còn dư cuối năm được chuyển sang năm sau tối đa bao nhiêu ngày và phải dùng trước ngày nào."
        :error="error"
        :loading="saving"
        submit-label="Lưu chính sách"
        @update:model-value="close"
        @submit="submit"
    >
        <div v-for="type in types" :key="type.id" class="mb-4">
            <div class="font-weight-bold mb-2">{{ type.name }} ({{ type.annual_entitlement_days }} ngày/năm)</div>
            <v-switch
                v-model="forms[type.id].allow_carry_forward"
                color="primary"
                inset
                hide-details
                label="Cho chuyển phép dư sang năm sau"
            />
            <div v-if="forms[type.id].allow_carry_forward" class="d-flex flex-wrap ga-3 mt-3">
                <v-text-field
                    v-model.number="forms[type.id].max_carry_forward_days"
                    type="number"
                    min="0.5"
                    step="0.5"
                    label="Tối đa"
                    suffix="ngày"
                    density="comfortable"
                    style="max-width: 150px"
                    :error-messages="errors[type.id]?.max_carry_forward_days"
                />
                <v-select
                    v-model="forms[type.id].expiry_month"
                    :items="MONTHS"
                    label="Hết hạn tháng"
                    density="comfortable"
                    style="max-width: 170px"
                />
                <v-select
                    v-model="forms[type.id].expiry_day"
                    :items="dayOptions(forms[type.id].expiry_month)"
                    label="Ngày"
                    density="comfortable"
                    style="max-width: 110px"
                />
            </div>
            <div class="text-caption text-medium-emphasis mt-1">
                <template v-if="forms[type.id].allow_carry_forward">
                    Ví dụ: còn dư 8 ngày cuối năm → chuyển
                    {{ Math.min(8, forms[type.id].max_carry_forward_days || 0) }} ngày, phải dùng trước
                    {{ forms[type.id].expiry_day }}/{{ forms[type.id].expiry_month }} năm sau, quá hạn thì mất phần chưa
                    dùng.
                </template>
                <template v-else>Phép dư cuối năm sẽ mất.</template>
            </div>
        </div>
    </FormDialog>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
import FormDialog from "../../components/common/FormDialog.vue";
import leaveBalanceService from "../../services/leaveBalanceService";
import { useToastStore } from "../../stores/useToastStore";

// Chính sách chuyển phép (2026-10-15) — chỉ Admin (quyền leave.manage).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const types = ref([]);
const forms = reactive({});
const errors = ref({});
const error = ref("");
const saving = ref(false);

const MONTHS = Array.from({ length: 12 }, (_, i) => ({ title: `Tháng ${i + 1}`, value: i + 1 }));
const dayOptions = (month) => Array.from({ length: new Date(2025, month, 0).getDate() }, (_, i) => i + 1);

async function load() {
    error.value = "";
    try {
        const list = (await leaveBalanceService.leaveTypes()).data.filter((t) => t.annual_entitlement_days > 0);
        types.value = list;
        for (const t of list) {
            const [month, day] = (t.carry_forward_expiry ?? "03-31").split("-").map(Number);
            forms[t.id] = {
                allow_carry_forward: !!t.allow_carry_forward,
                max_carry_forward_days: t.max_carry_forward_days ?? 5,
                expiry_month: month,
                expiry_day: day,
            };
        }
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được chính sách phép.";
    }
}

async function submit() {
    saving.value = true;
    errors.value = {};
    error.value = "";
    try {
        for (const t of types.value) {
            const f = forms[t.id];
            const pad = (n) => String(n).padStart(2, "0");
            try {
                await leaveBalanceService.updatePolicy(t.id, {
                    allow_carry_forward: f.allow_carry_forward,
                    max_carry_forward_days: f.allow_carry_forward ? f.max_carry_forward_days : null,
                    carry_forward_expiry: f.allow_carry_forward
                        ? `${pad(f.expiry_month)}-${pad(Math.min(f.expiry_day, dayOptions(f.expiry_month).length))}`
                        : null,
                });
            } catch (e) {
                if (e.response?.status === 422) {
                    errors.value = { ...errors.value, [t.id]: e.response.data.errors };
                    throw e;
                }
                throw e;
            }
        }
        toast.success("Đã lưu chính sách chuyển phép.");
        emit("saved");
        close();
    } catch (e) {
        if (e.response?.status !== 422) {
            error.value = e.response?.data?.message ?? "Không lưu được chính sách.";
        }
    } finally {
        saving.value = false;
    }
}

function close() {
    emit("update:modelValue", false);
}

watch(
    () => props.modelValue,
    (open) => open && load(),
);
</script>
