<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-account-multiple-plus-outline"
        size="sm"
        title="Thêm thành viên"
        subtitle="Chỉ hiện nhân viên đang làm việc và chưa ở trong dự án."
        :error="loadError"
        :loading="saving"
        submit-label="Thêm vào dự án"
        :submit-disabled="!selected.length"
        @update:model-value="close"
        @submit="submit"
    >
        <SearchSelect
            v-model="selected"
            :items="options"
            :loading="loading"
            multiple
            chips
            closable-chips
            no-filter
            placeholder="Gõ tên hoặc mã nhân viên để tìm"
            @update:search="onSearch"
        />
    </FormDialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import projectService from "../../services/projectService";
import FormDialog from "../../components/common/FormDialog.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    projectId: { type: Number, required: true },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const selected = ref([]);
const candidates = ref([]);
// Giữ tên người đã chọn dù lần tìm sau không còn khớp (chip không bị mất nhãn).
const picked = ref({});
const loading = ref(false);
const saving = ref(false);
const loadError = ref("");

const options = computed(() => {
    const list = [...candidates.value];
    for (const id of selected.value) {
        if (!list.some((e) => e.id === id) && picked.value[id]) {
            list.push(picked.value[id]);
        }
    }
    return list.map((e) => ({
        value: e.id,
        title: `${e.full_name} (${e.code})${e.position ? " · " + e.position : ""}${e.department ? " · " + e.department : ""}`,
    }));
});

watch(selected, (ids) => {
    for (const id of ids) {
        const found = candidates.value.find((e) => e.id === id);
        if (found) {
            picked.value[id] = found;
        }
    }
});

let timer = null;
function onSearch(text) {
    clearTimeout(timer);
    timer = setTimeout(() => load(text), 300);
}

async function load(search = "") {
    loading.value = true;
    try {
        candidates.value = (await projectService.memberCandidates(props.projectId, search || undefined)).data.data;
    } catch (e) {
        candidates.value = [];
        loadError.value = e.response?.data?.message ?? "Không tải được danh sách nhân viên.";
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            selected.value = [];
            picked.value = {};
            loadError.value = "";
            load();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    saving.value = true;
    loadError.value = "";
    try {
        const response = await projectService.addMembers(props.projectId, selected.value);
        toast.success(response.data.message);
        emit("saved");
        close();
    } catch (e) {
        const errors = e.response?.data?.errors;
        loadError.value =
            (errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message) ?? "Không thêm được thành viên.";
    } finally {
        saving.value = false;
    }
}
</script>
