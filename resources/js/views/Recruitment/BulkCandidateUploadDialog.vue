<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-file-multiple-outline"
        size="xl"
        title="Tải nhiều CV"
        :subtitle="`Đợt tuyển: ${opening?.title ?? ''} — kéo thả nhiều file PDF, họ tên tự điền từ tên file, chỉ cần bổ sung email.`"
        :error="loadError"
        :loading="loading"
        :submit-label="rows.length ? `Gửi ${pendingRows.length} CV` : 'Gửi CV'"
        :submit-disabled="!pendingRows.length"
        @update:model-value="close"
        @submit="submit"
    >
        <div
            class="drop-zone rounded-lg pa-6 text-center mb-4"
            :class="{ 'drop-zone--active': dragging }"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
            @click="fileInput?.click()"
        >
            <v-icon size="36" class="mb-2">mdi-cloud-upload-outline</v-icon>
            <div class="text-body-1 font-weight-medium">
                Kéo thả các file CV (PDF) vào đây hoặc bấm để chọn
            </div>
            <div class="text-caption" style="opacity: 0.7">
                Tối đa {{ MAX_FILES }} file mỗi lần, mỗi file ≤ 10MB
            </div>
            <input
                ref="fileInput"
                type="file"
                accept=".pdf,application/pdf"
                multiple
                hidden
                @change="onPick"
            />
        </div>

        <v-alert
            v-if="skipped.length"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            Bỏ qua {{ skipped.length }} file không hợp lệ:
            {{ skipped.join(", ") }}
        </v-alert>

        <v-table
            v-if="rows.length"
            density="comfortable"
            class="border rounded-lg"
        >
            <thead>
                <tr>
                    <th style="width: 22%">File</th>
                    <th>Họ và tên <span class="text-error">*</span></th>
                    <th>Email <span class="text-error">*</span></th>
                    <th style="width: 16%">Số điện thoại</th>
                    <th style="width: 48px" />
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, index) in rows"
                    :key="row.key"
                    :class="{ 'row-done': row.done }"
                >
                    <td class="text-caption" style="word-break: break-all">
                        <v-icon
                            size="16"
                            :color="row.done ? 'success' : undefined"
                        >
                            {{
                                row.done
                                    ? "mdi-check-circle"
                                    : "mdi-file-pdf-box"
                            }}
                        </v-icon>
                        {{ row.file.name }}
                    </td>
                    <td>
                        <v-text-field
                            v-model="row.full_name"
                            density="compact"
                            hide-details="auto"
                            :disabled="row.done"
                            :error-messages="row.errors.full_name"
                        />
                    </td>
                    <td>
                        <v-text-field
                            v-model="row.email"
                            type="email"
                            density="compact"
                            hide-details="auto"
                            :disabled="row.done"
                            :error-messages="row.errors.email"
                        />
                    </td>
                    <td>
                        <v-text-field
                            v-model="row.phone"
                            density="compact"
                            inputmode="numeric"
                            maxlength="10"
                            hide-details="auto"
                            :disabled="row.done"
                            :error-messages="row.errors.phone"
                        />
                    </td>
                    <td>
                        <v-btn
                            v-if="!row.done"
                            icon="mdi-close"
                            size="x-small"
                            variant="text"
                            :disabled="loading"
                            @click="rows.splice(index, 1)"
                        />
                    </td>
                </tr>
            </tbody>
        </v-table>
        <div v-if="rows.length" class="text-caption mt-2" style="opacity: 0.7">
            CV gửi lần lượt; dòng lỗi giữ lại để sửa rồi gửi tiếp, dòng xong
            được đánh dấu ✓.
        </div>
    </FormDialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import FormDialog from "../../components/common/FormDialog.vue";
import { useToastStore } from "../../stores/useToastStore";

const MAX_FILES = 30;
const MAX_BYTES = 10 * 1024 * 1024;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    opening: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const fileInput = ref(null);
const dragging = ref(false);
const loading = ref(false);
const loadError = ref("");
const rows = ref([]);
const skipped = ref([]);
let keySeq = 0;

const pendingRows = computed(() => rows.value.filter((r) => !r.done));

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            rows.value = [];
            skipped.value = [];
            loadError.value = "";
        }
    },
);

// "Nguyen_Van_A-CV.pdf" -> "Nguyen Van A": bỏ đuôi, chữ "CV", ký tự nối; viết hoa đầu từ.
function nameFromFile(fileName) {
    return fileName
        .replace(/\.pdf$/i, "")
        .replace(/[_\-.]+/g, " ")
        .replace(/\b(cv|resume|hoso|ho so)\b/gi, "")
        .replace(/\s+/g, " ")
        .trim()
        .replace(/(^|\s)(\p{L})/gu, (m, space, ch) => space + ch.toUpperCase());
}

function addFiles(fileList) {
    const files = [...fileList];
    skipped.value = [];
    for (const file of files) {
        const isPdf =
            /\.pdf$/i.test(file.name) || file.type === "application/pdf";
        if (!isPdf || file.size > MAX_BYTES) {
            skipped.value.push(file.name);
            continue;
        }
        if (rows.value.length >= MAX_FILES) {
            skipped.value.push(`${file.name} (quá ${MAX_FILES} file)`);
            continue;
        }
        rows.value.push({
            key: ++keySeq,
            file,
            full_name: nameFromFile(file.name),
            email: "",
            phone: "",
            errors: {},
            done: false,
        });
    }
}

function onDrop(event) {
    dragging.value = false;
    addFiles(event.dataTransfer?.files ?? []);
}

function onPick(event) {
    addFiles(event.target.files ?? []);
    event.target.value = "";
}

function close() {
    emit("update:modelValue", false);
}

// Kiểm tra nhanh phía trình duyệt — backend vẫn kiểm tra đầy đủ (trùng, giới hạn...).
function validateRow(row) {
    const errors = {};
    if (!row.full_name.trim()) errors.full_name = ["Nhập họ tên"];
    if (!EMAIL_PATTERN.test(row.email.trim()))
        errors.email = ["Email không hợp lệ"];
    if (row.phone && !/^\d{10}$/.test(row.phone))
        errors.phone = ["SĐT gồm 10 chữ số"];
    row.errors = errors;
    return Object.keys(errors).length === 0;
}

async function submit() {
    loadError.value = "";
    const targets = pendingRows.value;
    if (!targets.map(validateRow).every(Boolean)) {
        loadError.value =
            "Còn dòng thiếu/sai thông tin — sửa các ô đỏ rồi gửi lại.";
        return;
    }

    loading.value = true;
    let done = 0;
    try {
        // Gửi LẦN LƯỢT: giới hạn suất và kiểm tra trùng phụ thuộc thứ tự, gửi song song dễ báo sai.
        for (const row of targets) {
            const data = new FormData();
            data.append("full_name", row.full_name.trim());
            data.append("email", row.email.trim());
            if (row.phone) data.append("phone", row.phone);
            data.append("cv_file", row.file);
            try {
                await recruitmentService.addCandidate(props.opening.id, data);
                row.done = true;
                row.errors = {};
                done++;
            } catch (e) {
                const errors = e.response?.data?.errors ?? {};
                row.errors = errors;
                // Lỗi của cả đợt tuyển (đủ suất, đã đóng, quá hạn) -> dừng, các dòng sau cũng sẽ lỗi y vậy.
                if (errors.opening) {
                    loadError.value = errors.opening[0];
                    break;
                }
                if (!Object.keys(errors).length) {
                    row.errors = {
                        full_name: [
                            e.response?.data?.message ?? "Không gửi được",
                        ],
                    };
                }
            }
        }
    } finally {
        loading.value = false;
    }

    if (done) {
        toast.success(`Đã gửi ${done} CV, chờ Admin duyệt.`);
        emit("saved");
    }
    if (!pendingRows.value.length) {
        close();
    } else if (!loadError.value) {
        loadError.value = `Còn ${pendingRows.value.length} CV chưa gửi được — xem lỗi ở từng dòng.`;
    }
}
</script>

<style scoped>
.drop-zone {
    border: 2px dashed rgba(var(--v-theme-primary), 0.4);
    cursor: pointer;
    transition:
        background 0.15s,
        border-color 0.15s;
}
.drop-zone:hover,
.drop-zone--active {
    background: rgba(var(--v-theme-primary), 0.06);
    border-color: rgb(var(--v-theme-primary));
}
.row-done {
    opacity: 0.55;
}
</style>
