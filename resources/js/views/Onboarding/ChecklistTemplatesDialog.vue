<template>
    <v-dialog
        :model-value="modelValue"
        max-width="1100"
        scrollable
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <v-card rounded="xl">
            <v-card-title class="d-flex align-center ga-2 pt-5 px-6">
                <v-icon icon="mdi-clipboard-list-outline" />
                Mẫu checklist
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="$emit('update:modelValue', false)" />
            </v-card-title>
            <v-card-subtitle class="px-6 text-wrap">
                Checklist tạo cho nhân viên sẽ chép các việc từ mẫu — sửa mẫu không làm đổi checklist đã tạo.
            </v-card-subtitle>

            <v-card-text class="pa-0">
                <div class="templates-layout">
                    <!-- Danh sách mẫu -->
                    <div class="templates-list pa-3">
                        <template v-for="type in ['onboarding', 'offboarding']" :key="type">
                            <div class="d-flex align-center px-2 mt-2">
                                <span class="text-caption font-weight-bold text-uppercase" style="opacity: 0.6">
                                    {{ CHECKLIST_TYPE_LABELS[type] }}
                                </span>
                                <v-spacer />
                                <v-btn
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    prepend-icon="mdi-plus"
                                    @click="startNew(type)"
                                >
                                    Mẫu mới
                                </v-btn>
                            </div>
                            <v-list density="compact" nav>
                                <v-list-item
                                    v-for="t in templates.filter((x) => x.type === type)"
                                    :key="t.id"
                                    :active="editing?.id === t.id"
                                    rounded="lg"
                                    @click="edit(t)"
                                >
                                    <v-list-item-title>{{ t.name }}</v-list-item-title>
                                    <v-list-item-subtitle>
                                        {{ t.items.length }} việc
                                        <template v-if="t.is_default"> · mặc định</template>
                                        <template v-if="!t.is_active"> · ngừng dùng</template>
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </template>
                    </div>

                    <!-- Sửa mẫu -->
                    <div class="templates-editor pa-5">
                        <div v-if="!editing" class="text-center py-10" style="opacity: 0.6">
                            Chọn 1 mẫu bên trái để sửa, hoặc bấm "Mẫu mới".
                        </div>
                        <v-form v-else ref="formRef" @submit.prevent="save">
                            <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{
                                error
                            }}</v-alert>

                            <div class="d-flex flex-wrap align-center ga-3 mb-3">
                                <v-chip color="primary" variant="tonal">
                                    {{ CHECKLIST_TYPE_LABELS[editing.type] }}
                                </v-chip>
                                <v-text-field
                                    v-model="editing.name"
                                    label="Tên mẫu"
                                    density="compact"
                                    variant="outlined"
                                    style="min-width: 260px"
                                    :rules="[(v) => !!v?.trim() || 'Vui lòng nhập tên mẫu']"
                                    :error-messages="errors.name"
                                />
                                <v-switch
                                    v-model="editing.is_default"
                                    label="Mặc định"
                                    color="primary"
                                    density="compact"
                                    hide-details
                                    inset
                                />
                                <v-switch
                                    v-model="editing.is_active"
                                    label="Đang dùng"
                                    color="primary"
                                    density="compact"
                                    hide-details
                                    inset
                                />
                            </div>
                            <div class="text-caption mb-2" style="opacity: 0.7">
                                Hạn = {{ REFERENCE_DATE_LABELS[editing.type].toLowerCase() }} + số ngày (số âm = trước
                                ngày đó). Mẫu mặc định được dùng khi hệ thống tự tạo checklist.
                            </div>

                            <div v-for="(item, index) in editing.items" :key="item.key" class="template-item py-3">
                                <div class="d-flex flex-wrap align-start ga-2">
                                    <div class="d-flex flex-column">
                                        <v-btn
                                            icon="mdi-chevron-up"
                                            variant="text"
                                            size="x-small"
                                            :disabled="index === 0"
                                            @click="move(index, -1)"
                                        />
                                        <v-btn
                                            icon="mdi-chevron-down"
                                            variant="text"
                                            size="x-small"
                                            :disabled="index === editing.items.length - 1"
                                            @click="move(index, 1)"
                                        />
                                    </div>
                                    <div class="flex-grow-1 d-flex flex-column ga-2" style="min-width: 260px">
                                        <v-text-field
                                            v-model="item.title"
                                            :label="`Việc ${index + 1}`"
                                            density="compact"
                                            variant="outlined"
                                            hide-details="auto"
                                            :rules="[(v) => !!v?.trim() || 'Vui lòng nhập tên việc']"
                                            :error-messages="errors[`items.${index}.title`]"
                                        />
                                        <v-text-field
                                            v-model="item.description"
                                            label="Mô tả (tuỳ chọn)"
                                            density="compact"
                                            variant="outlined"
                                            hide-details
                                        />
                                    </div>
                                    <div class="d-flex flex-column ga-2" style="width: 200px">
                                        <v-select
                                            v-model="item.responsible"
                                            :items="responsibleOptions"
                                            label="Phụ trách"
                                            density="compact"
                                            variant="outlined"
                                            hide-details
                                        />
                                        <v-text-field
                                            v-model.number="item.due_offset_days"
                                            type="number"
                                            label="Hạn (số ngày)"
                                            density="compact"
                                            variant="outlined"
                                            hide-details="auto"
                                            :error-messages="errors[`items.${index}.due_offset_days`]"
                                        />
                                    </div>
                                    <div class="d-flex flex-column ga-1" style="width: 230px">
                                        <v-select
                                            v-model="item.auto_key"
                                            :items="autoKeyOptions"
                                            label="Tự đánh dấu"
                                            density="compact"
                                            variant="outlined"
                                            hide-details="auto"
                                            :error-messages="errors[`items.${index}.auto_key`]"
                                        />
                                        <v-checkbox
                                            v-model="item.is_required"
                                            label="Bắt buộc"
                                            density="compact"
                                            hide-details
                                        />
                                    </div>
                                    <v-btn
                                        icon="mdi-delete-outline"
                                        variant="text"
                                        color="error"
                                        size="small"
                                        :disabled="editing.items.length === 1"
                                        @click="editing.items.splice(index, 1)"
                                    />
                                </div>
                            </div>

                            <v-btn variant="tonal" prepend-icon="mdi-plus" size="small" class="mt-2" @click="addItem"
                                >Thêm việc</v-btn
                            >
                            <div v-if="errors.items" class="text-error text-caption mt-1">{{ errors.items }}</div>

                            <div class="d-flex ga-2 mt-5">
                                <v-btn
                                    v-if="editing.id"
                                    variant="text"
                                    color="error"
                                    prepend-icon="mdi-delete-outline"
                                    :loading="deleting"
                                    @click="remove"
                                >
                                    {{ confirmingDelete ? "Bấm lần nữa để xóa" : "Xóa mẫu" }}
                                </v-btn>
                                <v-spacer />
                                <v-btn variant="text" @click="editing = null">Bỏ thay đổi</v-btn>
                                <v-btn color="primary" variant="flat" type="submit" :loading="saving">Lưu mẫu</v-btn>
                            </div>
                        </v-form>
                    </div>
                </div>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import onboardingService from "../../services/onboardingService";
import { useToastStore } from "../../stores/useToastStore";
import {
    AUTO_KEYS_BY_TYPE,
    AUTO_KEY_LABELS,
    CHECKLIST_TYPE_LABELS,
    REFERENCE_DATE_LABELS,
    RESPONSIBLE_LABELS,
} from "../../composables/onboardingLabels";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const templates = ref([]);
const editing = ref(null);
const formRef = ref(null);
const saving = ref(false);
const deleting = ref(false);
const confirmingDelete = ref(false);
const error = ref("");
const errors = ref({});
let keySeq = 0;

const responsibleOptions = Object.entries(RESPONSIBLE_LABELS).map(([value, title]) => ({ title, value }));
const autoKeyOptions = computed(() => [
    { title: "Không (đánh dấu tay)", value: null },
    ...(AUTO_KEYS_BY_TYPE[editing.value?.type] ?? []).map((value) => ({ title: AUTO_KEY_LABELS[value], value })),
]);

async function load() {
    try {
        templates.value = (await onboardingService.templates()).data.data;
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được mẫu checklist.";
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            editing.value = null;
            load();
        }
    },
);

function blankItem() {
    return {
        key: ++keySeq,
        title: "",
        description: "",
        responsible: "hr",
        due_offset_days: 0,
        is_required: true,
        auto_key: null,
    };
}

function edit(template) {
    confirmingDelete.value = false;
    errors.value = {};
    error.value = "";
    editing.value = {
        ...template,
        items: template.items.map((item) => ({ ...item, key: ++keySeq, description: item.description ?? "" })),
    };
}

function startNew(type) {
    confirmingDelete.value = false;
    errors.value = {};
    error.value = "";
    editing.value = { id: null, type, name: "", is_default: false, is_active: true, items: [blankItem()] };
}

function addItem() {
    editing.value.items.push(blankItem());
}

function move(index, step) {
    const items = editing.value.items;
    [items[index], items[index + step]] = [items[index + step], items[index]];
}

async function save() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;
    saving.value = true;
    errors.value = {};
    error.value = "";
    const payload = {
        type: editing.value.type,
        name: editing.value.name.trim(),
        is_default: editing.value.is_default,
        is_active: editing.value.is_active,
        items: editing.value.items.map(
            ({ title, description, responsible, due_offset_days, is_required, auto_key }) => ({
                title: title.trim(),
                description: description?.trim() || null,
                responsible,
                due_offset_days: Number(due_offset_days) || 0,
                is_required,
                auto_key,
            }),
        ),
    };
    try {
        const response = editing.value.id
            ? await onboardingService.updateTemplate(editing.value.id, payload)
            : await onboardingService.createTemplate(payload);
        toast.success("Đã lưu mẫu checklist.");
        await load();
        edit(response.data.data);
        emit("saved");
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
            error.value = "Vui lòng kiểm tra lại các ô báo đỏ.";
        } else {
            error.value = e.response?.data?.message ?? "Không lưu được mẫu.";
        }
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;
        return;
    }
    deleting.value = true;
    try {
        await onboardingService.removeTemplate(editing.value.id);
        toast.success("Đã xóa mẫu checklist.");
        editing.value = null;
        await load();
        emit("saved");
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không xóa được mẫu.";
    } finally {
        deleting.value = false;
    }
}
</script>

<style scoped>
.templates-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 480px;
}
.templates-list {
    border-right: 1px solid rgb(var(--v-theme-hairline));
}
.template-item + .template-item {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
@media (max-width: 760px) {
    .templates-layout {
        grid-template-columns: 1fr;
    }
    .templates-list {
        border-right: 0;
        border-bottom: 1px solid rgb(var(--v-theme-hairline));
    }
}
</style>
