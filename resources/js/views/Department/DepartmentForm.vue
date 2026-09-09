<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="HỒ SƠ PHÒNG BAN"
        :title="isEdit ? 'Sửa phòng ban' : 'Thêm phòng ban'"
        :subtitle="
            isEdit
                ? 'Cập nhật thông tin và vị trí trong cơ cấu tổ chức.'
                : 'Tạo phòng ban mới và gắn vào cơ cấu tổ chức.'
        "
        :error="store.loadError"
        :loading="store.loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin cơ bản">
            <v-row dense>
                <v-col cols="12" sm="7">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tên phòng ban <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.name"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="Nhập tên phòng ban"
                        :error-messages="store.errors.name"
                    />
                </v-col>

                <v-col cols="12" sm="5">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Mã phòng ban
                    </div>
                    <v-text-field
                        :model-value="
                            isEdit ? department.code : 'Tự động sau khi lưu'
                        "
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        readonly
                        prepend-inner-icon="mdi-auto-fix"
                        :class="{ 'text-medium-emphasis': !isEdit }"
                    />
                </v-col>
            </v-row>
        </FormSection>

        <FormSection title="Cơ cấu tổ chức">
            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">
                    Phòng ban cha
                </div>
                <SearchSelect
                    v-model="form.parent_id"
                    :items="parentOptions"
                    item-title="title"
                    item-value="id"
                    placeholder="Không có — đây là phòng ban gốc"
                    clearable
                    :error-messages="store.errors.parent_id"
                />
            </div>

            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">
                    Trưởng phòng
                </div>
                <SearchSelect
                    v-model="form.manager_id"
                    :items="managerOptions"
                    item-title="title"
                    item-value="id"
                    placeholder="Chưa phân công"
                    clearable
                    :error-messages="store.errors.manager_id"
                />
            </div>

            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">Mô tả</div>
                <v-textarea
                    v-model="form.description"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    rows="3"
                    no-resize
                    placeholder="Chức năng, nhiệm vụ chính của phòng ban..."
                    :error-messages="store.errors.description"
                />
            </div>

            <div class="d-flex align-center justify-space-between">
                <div>
                    <div class="text-body-2 font-weight-medium">
                        Trạng thái hoạt động
                    </div>
                    <div class="text-caption" style="opacity: 0.65">
                        Phòng ban ngừng hoạt động vẫn được lưu nhưng không
                        dùng để phân công mới.
                    </div>
                </div>
                <v-switch
                    v-model="form.is_active"
                    color="success"
                    density="compact"
                    hide-details
                    inset
                    class="flex-grow-0 ms-4"
                />
            </div>
        </FormSection>

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, onMounted, reactive, watch } from "vue";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useToastStore } from "../../stores/useToastStore";
import SearchSelect from "../../components/common/SearchSelect.vue";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    // null = thêm mới, object = sửa phòng ban đang chọn
    department: {
        type: Object,
        default: null,
    },
    parentOptions: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const store = useDepartmentStore();
const toast = useToastStore();
const employeeStore = useEmployeeStore();

const isEdit = computed(() => props.department !== null);

const form = reactive({
    name: "",
    parent_id: null,
    manager_id: null,
    description: "",
    is_active: true,
});

function fillForm() {
    form.name = props.department?.name ?? "";
    form.parent_id = props.department?.parent_id ?? null;
    form.manager_id = props.department?.manager_id ?? null;
    form.description = props.department?.description ?? "";
    // DB trả về 1/0, ép về boolean cho v-switch
    form.is_active = Boolean(props.department?.is_active ?? true);
}

// Danh sách chọn Trưởng phòng — lấy toàn bộ nhân viên đang có (per_page lớn để
// không bị cắt trang), vì đây là dropdown chọn 1 người chứ không phải bảng liệt kê.
const managerOptions = computed(() =>
    employeeStore.employees.map((employee) => ({
        id: employee.id,
        title: `${employee.full_name} (${employee.code})`,
    })),
);

onMounted(() => {
    employeeStore.fetchList({ per_page: 1000 });
});

// Mỗi lần mở modal: nạp lại dữ liệu và xóa lỗi của lần mở trước
watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            store.resetErrors();
            fillForm();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    try {
        const saved = isEdit.value
            ? await store.update(props.department.id, { ...form })
            : await store.create({ ...form });
        toast.success(
            isEdit.value ? "Đã cập nhật phòng ban." : "Đã thêm phòng ban mới.",
        );
        // Kèm luôn bản ghi vừa lưu: nơi gọi có thể cần `id` để dùng ngay, ví dụ
        // form nhân viên tạo nhanh phòng ban rồi tự gán vào ô đang chọn. Chỗ nào
        // không cần thì cứ bỏ qua tham số, thêm tham số không phá listener cũ.
        // API trả bản ghi PHẲNG (`response()->json(new DepartmentResource(...))`
        // không bọc trong `data`), nên `saved` chính là object phòng ban.
        emit("saved", saved);
        close();
    } catch {
        // Lỗi đã được store xử lý (422 -> store.errors, còn lại -> store.loadError),
        // giữ modal mở để người dùng sửa lại dữ liệu.
    }
}
</script>
