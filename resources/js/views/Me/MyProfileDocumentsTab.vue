<template>
    <div>
        <v-alert
            v-if="documentsError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ documentsError }}
        </v-alert>

        <div class="d-flex justify-end mb-3">
            <v-btn
                color="primary"
                variant="flat"
                prepend-icon="mdi-upload-outline"
                @click="openUploadDialog"
            >
                Tải lên tài liệu
            </v-btn>
        </div>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Loại tài liệu</th>
                        <th>Tên tài liệu</th>
                        <th>Kích thước</th>
                        <th>Người tải lên</th>
                        <th>Ngày tải lên</th>
                        <th class="text-center">Xem</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="documentsLoading">
                        <td colspan="6" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!documents.length">
                        <td colspan="6" class="text-center py-6" style="opacity: 0.6">
                            Chưa có tài liệu nào.
                        </td>
                    </tr>
                    <tr v-for="doc in documents" v-else :key="doc.id">
                        <td>
                            {{ DOCUMENT_TYPE_MAP[doc.document_type] ?? doc.document_type }}
                        </td>
                        <td>{{ doc.document_name }}</td>
                        <td>{{ formatFileSize(doc.file_size) }}</td>
                        <td>{{ doc.uploaded_by ?? "—" }}</td>
                        <td>{{ formatDate(doc.created_at) }}</td>
                        <td class="text-center">
                            <v-btn
                                icon="mdi-eye-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                @click="$emit('preview', doc.download_url, doc.file_name)"
                            >
                                <v-icon icon="mdi-eye-outline" />
                                <v-tooltip activator="parent" location="top"
                                    >Xem trước</v-tooltip
                                >
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <v-dialog v-model="uploadDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Tải lên tài liệu
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Loại tài liệu <span class="text-error">*</span>
                        </div>
                        <v-select
                            v-model="uploadForm.document_type"
                            :items="documentTypeOptions"
                            placeholder="Chưa chọn"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            persistent-placeholder
                            :error-messages="uploadErrors.document_type"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tên tài liệu <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="uploadForm.document_name"
                            placeholder="Ví dụ: CCCD mặt trước"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="uploadErrors.document_name"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Tệp đính kèm <span class="text-error">*</span>
                        </div>
                        <v-file-input
                            v-model="uploadForm.file"
                            placeholder="Chọn PDF, Word (.docx), Excel (.xlsx), JPG hoặc PNG (tối đa 10MB)"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip"
                            accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"
                            :error-messages="uploadErrors.document_file"
                        />
                    </div>

                    <v-alert
                        v-if="uploadGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ uploadGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" :disabled="uploading" @click="closeUploadDialog">
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="uploading"
                        @click="submitUpload"
                    >
                        Tải lên
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Tab "Tài liệu" của MyProfile.vue — tách riêng theo yêu cầu dễ bảo trì.
// Tự tải + tự nộp tài liệu cá nhân qua endpoint /employees/me/documents.
import { onMounted, ref } from "vue";
import employeeService from "../../services/employeeService";
import { useToastStore } from "../../stores/useToastStore";

defineEmits(["preview"]);

const toast = useToastStore();

const DOCUMENT_TYPE_MAP = {
    cccd: "CCCD/CMND",
    resume: "Sơ yếu lý lịch",
    certificate: "Bằng cấp/Chứng chỉ",
    other: "Khác",
};
const documentTypeOptions = Object.entries(DOCUMENT_TYPE_MAP).map(([value, title]) => ({
    title,
    value,
}));

function formatDate(value) {
    if (!value) {
        return null;
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatFileSize(bytes) {
    if (!bytes) {
        return "—";
    }
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const documents = ref([]);
const documentsLoading = ref(false);
const documentsError = ref("");

async function loadDocuments() {
    documentsLoading.value = true;
    documentsError.value = "";
    try {
        const response = await employeeService.myDocuments();
        documents.value = response.data.data;
    } catch (e) {
        documentsError.value = e.response?.data?.message ?? "Không thể tải danh sách tài liệu.";
    } finally {
        documentsLoading.value = false;
    }
}

/* --------------------------- Tải lên tài liệu --------------------------- */

const uploadDialog = ref(false);
const uploadForm = ref({ document_type: null, document_name: "", file: null });
const uploadErrors = ref({});
const uploadGeneralError = ref("");
const uploading = ref(false);

function openUploadDialog() {
    uploadForm.value = { document_type: null, document_name: "", file: null };
    uploadErrors.value = {};
    uploadGeneralError.value = "";
    uploadDialog.value = true;
}

function closeUploadDialog() {
    uploadDialog.value = false;
}

async function submitUpload() {
    uploadErrors.value = {};
    uploadGeneralError.value = "";
    uploading.value = true;
    try {
        const formData = new FormData();
        formData.append("document_type", uploadForm.value.document_type ?? "");
        formData.append("document_name", uploadForm.value.document_name);
        // v-file-input trả về mảng (kể cả khi multiple=false) — lấy phần tử đầu.
        const file = Array.isArray(uploadForm.value.file)
            ? uploadForm.value.file[0]
            : uploadForm.value.file;
        if (file) {
            formData.append("document_file", file);
        }

        const response = await employeeService.uploadMyDocument(formData);
        documents.value = [response.data.data, ...documents.value];
        toast.success("Đã tải lên tài liệu.");
        closeUploadDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            uploadErrors.value = {
                document_type: data.errors.document_type?.[0],
                document_name: data.errors.document_name?.[0],
                document_file: data.errors.document_file?.[0],
            };
        } else {
            uploadGeneralError.value = data?.message ?? "Không thể tải lên tài liệu.";
        }
    } finally {
        uploading.value = false;
    }
}

onMounted(() => {
    loadDocuments();
});
</script>
