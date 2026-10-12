<template>
    <!-- Tra cứu lịch sử ứng tuyển theo email / SĐT. Truyền sẵn email/phone (từ 1 ứng viên)
         thì tra ngay; không truyền thì là ô tra cứu tự do (trang Tuyển dụng). -->
    <v-dialog
        :model-value="modelValue"
        max-width="680"
        scrollable
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <v-card rounded="xl">
            <v-card-title class="d-flex align-center ga-2 pt-5 px-6">
                <v-icon icon="mdi-account-search-outline" />
                <span class="flex-grow-1">{{ title }}</span>
                <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
            </v-card-title>

            <v-card-text class="px-6">
                <v-form class="d-flex flex-wrap ga-2 mb-4" @submit.prevent="search">
                    <v-text-field
                        v-model="email"
                        label="Email"
                        density="compact"
                        variant="outlined"
                        hide-details
                        clearable
                        style="min-width: 220px"
                    />
                    <v-text-field
                        v-model="phone"
                        label="Số điện thoại"
                        density="compact"
                        variant="outlined"
                        hide-details
                        clearable
                        inputmode="numeric"
                        style="max-width: 180px"
                    />
                    <v-btn
                        type="submit"
                        color="primary"
                        variant="flat"
                        :loading="loading"
                        :disabled="!email?.trim() && !phone?.trim()"
                    >
                        Tra cứu
                    </v-btn>
                </v-form>

                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>
                <div v-if="loading" class="d-flex justify-center py-6">
                    <v-progress-circular indeterminate size="24" />
                </div>
                <template v-else-if="searched">
                    <v-alert
                        v-if="!items.length"
                        type="success"
                        variant="tonal"
                        density="compact"
                        icon="mdi-account-plus-outline"
                    >
                        Chưa từng nộp CV vào công ty.
                    </v-alert>
                    <template v-else>
                        <div class="text-body-2 mb-2">
                            Đã nộp CV <strong>{{ items.length }}</strong> lần{{
                                excludeId ? " (không tính CV đang xem)" : ""
                            }}:
                        </div>
                        <CandidateHistoryList :items="items" @navigate="$emit('update:modelValue', false)" />
                    </template>
                </template>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import CandidateHistoryList from "./CandidateHistoryList.vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: "Tra cứu lịch sử ứng tuyển" },
    presetEmail: { type: String, default: "" },
    presetPhone: { type: String, default: "" },
    // Không tính chính CV đang xem.
    excludeId: { type: Number, default: null },
});

defineEmits(["update:modelValue"]);

const email = ref("");
const phone = ref("");
const items = ref([]);
const loading = ref(false);
const searched = ref(false);
const error = ref("");

async function search() {
    if (!email.value?.trim() && !phone.value?.trim()) return;
    loading.value = true;
    error.value = "";
    try {
        items.value = (
            await recruitmentService.candidateHistory({
                email: email.value?.trim() || undefined,
                phone: phone.value?.trim() || undefined,
                exclude_id: props.excludeId || undefined,
            })
        ).data.data;
        searched.value = true;
    } catch (e) {
        const errors = e.response?.data?.errors;
        error.value = errors ? Object.values(errors)[0]?.[0] : (e.response?.data?.message ?? "Không tra cứu được.");
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        email.value = props.presetEmail;
        phone.value = props.presetPhone;
        items.value = [];
        searched.value = false;
        error.value = "";
        if (email.value || phone.value) search();
    },
);
</script>
