<template>
    <v-navigation-drawer
        :model-value="modelValue"
        location="right"
        temporary
        width="480"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div v-if="loading && !checklist" class="d-flex justify-center py-10">
            <v-progress-circular indeterminate />
        </div>

        <div v-else-if="checklist" class="pa-5 d-flex flex-column ga-4">
            <div class="d-flex align-start ga-3">
                <v-avatar size="48" color="primary" variant="tonal">
                    <v-img v-if="checklist.employee?.avatar_url" :src="checklist.employee.avatar_url" cover />
                    <span v-else>{{ initials(checklist.employee?.full_name) }}</span>
                </v-avatar>
                <div class="flex-grow-1 min-width-0">
                    <div class="text-caption text-uppercase font-weight-bold" style="opacity: 0.6">
                        Checklist {{ CHECKLIST_TYPE_LABELS[checklist.type]?.toLowerCase() }}
                    </div>
                    <div class="text-h6 font-weight-bold">{{ checklist.employee?.full_name }}</div>
                    <div class="text-caption" style="opacity: 0.7">
                        {{ checklist.employee?.code }}
                        <template v-if="checklist.employee?.department"> · {{ checklist.employee.department }}</template>
                        <template v-if="checklist.employee?.position"> · {{ checklist.employee.position }}</template>
                    </div>
                </div>
                <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
            </div>

            <div>
                <div class="d-flex justify-space-between align-center mb-1">
                    <StatusChip :status="checklist.status" :map="CHECKLIST_STATUS_MAP" />
                    <span class="text-body-2 font-weight-bold">
                        {{ checklist.progress.done }}/{{ checklist.progress.total }} việc · {{ checklist.progress.percent }}%
                    </span>
                </div>
                <v-progress-linear
                    :model-value="checklist.progress.percent"
                    :color="checklist.status === 'completed' ? 'success' : 'primary'"
                    height="8"
                    rounded
                />
                <div class="text-caption mt-2" style="opacity: 0.7">
                    {{ REFERENCE_DATE_LABELS[checklist.type] }}: <strong>{{ formatDate(checklist.reference_date) }}</strong>
                    <template v-if="checklist.template_name"> · Mẫu: {{ checklist.template_name }}</template>
                </div>
            </div>

            <v-alert v-if="error" type="error" variant="tonal" density="compact">{{ error }}</v-alert>

            <ChecklistItemList
                :items="checklist.items"
                :can-manage="checklist.can_manage"
                :readonly="checklist.status === 'cancelled'"
                :busy-item-id="busyItemId"
                @toggle="toggle"
                @remove="removeItem"
            />

            <!-- Thêm việc riêng cho nhân viên này (không đổi mẫu). -->
            <v-sheet
                v-if="checklist.can_manage && checklist.status !== 'cancelled'"
                class="border rounded-lg pa-3"
                color="transparent"
            >
                <v-btn
                    v-if="!adding"
                    variant="text"
                    color="primary"
                    prepend-icon="mdi-plus"
                    size="small"
                    @click="startAdding"
                >
                    Thêm việc
                </v-btn>
                <v-form v-else ref="addFormRef" class="d-flex flex-column ga-2" @submit.prevent="submitItem">
                    <v-text-field
                        v-model="newItem.title"
                        label="Tên việc"
                        density="compact"
                        variant="outlined"
                        autofocus
                        :rules="[(v) => !!v?.trim() || 'Vui lòng nhập tên việc']"
                        :error-messages="itemErrors.title"
                    />
                    <div class="d-flex ga-2">
                        <v-select
                            v-model="newItem.responsible"
                            :items="responsibleOptions"
                            label="Phụ trách"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                        />
                        <InputDate v-model="newItem.due_date" label="Hạn" density="compact" :error-messages="itemErrors.due_date" />
                    </div>
                    <div class="d-flex justify-end ga-2">
                        <v-btn variant="text" size="small" @click="adding = false">Hủy</v-btn>
                        <v-btn color="primary" size="small" type="submit" :loading="savingItem">Thêm</v-btn>
                    </div>
                </v-form>
            </v-sheet>

            <div class="d-flex flex-wrap ga-2">
                <v-btn
                    v-if="checklist.employee"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-account-details-outline"
                    :to="canViewEmployee ? { name: 'employee-detail', params: { id: checklist.employee.id } } : undefined"
                    :disabled="!canViewEmployee"
                >
                    Hồ sơ nhân viên
                </v-btn>
                <v-btn
                    v-if="checklist.can_manage && checklist.status === 'in_progress'"
                    variant="text"
                    color="error"
                    size="small"
                    prepend-icon="mdi-cancel"
                    @click="confirmCancel = true"
                >
                    Hủy checklist
                </v-btn>
            </div>
        </div>

        <v-dialog v-model="confirmCancel" max-width="420">
            <v-card rounded="xl">
                <v-card-title class="pt-5 px-5">Hủy checklist?</v-card-title>
                <v-card-text>
                    Checklist của {{ checklist?.employee?.full_name }} sẽ dừng theo dõi. Các việc đã đánh dấu vẫn được giữ lại để tra cứu.
                </v-card-text>
                <v-card-actions class="px-5 pb-4">
                    <v-spacer />
                    <v-btn variant="text" @click="confirmCancel = false">Không</v-btn>
                    <v-btn color="error" variant="flat" :loading="cancelling" @click="cancelChecklist">Hủy checklist</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-navigation-drawer>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import onboardingService from "../../services/onboardingService";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";
import StatusChip from "../../components/common/StatusChip.vue";
import InputDate from "../../components/common/InputDate.vue";
import ChecklistItemList from "../../components/onboarding/ChecklistItemList.vue";
import { initials } from "../../composables/avatar";
import {
    CHECKLIST_STATUS_MAP,
    CHECKLIST_TYPE_LABELS,
    REFERENCE_DATE_LABELS,
    RESPONSIBLE_LABELS,
    formatDate,
} from "../../composables/onboardingLabels";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    checklistId: { type: [Number, null], default: null },
});

const emit = defineEmits(["update:modelValue", "changed"]);

const auth = useAuthStore();
const toast = useToastStore();

const checklist = ref(null);
const loading = ref(false);
const error = ref("");
const busyItemId = ref(null);
const adding = ref(false);
const addFormRef = ref(null);
const savingItem = ref(false);
const newItem = ref({ title: "", responsible: "hr", due_date: "" });
const itemErrors = ref({});
const confirmCancel = ref(false);
const cancelling = ref(false);

const canViewEmployee = computed(() => auth.permissions.includes("employee.view"));
const responsibleOptions = Object.entries(RESPONSIBLE_LABELS).map(([value, title]) => ({ title, value }));

function firstError(e) {
    const errors = e.response?.data?.errors;
    return errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message;
}

async function load() {
    if (!props.checklistId) return;
    loading.value = true;
    error.value = "";
    try {
        checklist.value = (await onboardingService.show(props.checklistId)).data.data;
    } catch (e) {
        error.value = firstError(e) ?? "Không tải được checklist.";
    } finally {
        loading.value = false;
    }
}

// Cho trang cha gọi lại khi có tín hiệu realtime.
defineExpose({ reload: load });

watch(
    () => [props.modelValue, props.checklistId],
    ([open, id], [, oldId]) => {
        if (!open) return;
        if (id !== oldId) {
            checklist.value = null;
            adding.value = false;
        }
        load();
    },
);

async function toggle(item, done) {
    busyItemId.value = item.id;
    error.value = "";
    try {
        checklist.value = (await onboardingService.updateItem(item.id, { done })).data.data;
        emit("changed");
        if (done && checklist.value.status === "completed") {
            toast.success("Đã hoàn thành checklist.");
        }
    } catch (e) {
        error.value = firstError(e) ?? "Không cập nhật được việc này.";
    } finally {
        busyItemId.value = null;
    }
}

async function removeItem(item) {
    busyItemId.value = item.id;
    try {
        checklist.value = (await onboardingService.removeItem(item.id)).data.data;
        emit("changed");
    } catch (e) {
        error.value = firstError(e) ?? "Không bỏ được việc này.";
    } finally {
        busyItemId.value = null;
    }
}

function startAdding() {
    newItem.value = { title: "", responsible: "hr", due_date: "" };
    itemErrors.value = {};
    adding.value = true;
}

async function submitItem() {
    const { valid } = await addFormRef.value.validate();
    if (!valid) return;
    savingItem.value = true;
    itemErrors.value = {};
    try {
        checklist.value = (await onboardingService.addItem(checklist.value.id, {
            title: newItem.value.title.trim(),
            responsible: newItem.value.responsible,
            due_date: newItem.value.due_date || null,
        })).data.data;
        adding.value = false;
        emit("changed");
    } catch (e) {
        itemErrors.value = Object.fromEntries(Object.entries(e.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]]));
        if (!e.response?.data?.errors) error.value = firstError(e) ?? "Không thêm được việc.";
    } finally {
        savingItem.value = false;
    }
}

async function cancelChecklist() {
    cancelling.value = true;
    try {
        checklist.value = (await onboardingService.cancel(checklist.value.id)).data.data;
        confirmCancel.value = false;
        toast.success("Đã hủy checklist.");
        emit("changed");
    } catch (e) {
        error.value = firstError(e) ?? "Không hủy được checklist.";
        confirmCancel.value = false;
    } finally {
        cancelling.value = false;
    }
}
</script>
