<script>
// Giới hạn tải tệp của TỪNG chỗ upload — đây là nơi DUY NHẤT ghi số MB/định
// dạng hiển thị cho người dùng, mỗi mục PHẢI khớp rule ở Backend (đổi 1 bên
// thì đổi cả bên kia, Backend mới là bên chặn thật, chỗ này chỉ để người dùng
// biết trước):
//   contract         StoreEmployeeContractRequest   contract_file  mimes:pdf                     max:5120
//   document         StoreEmployeeDocumentRequest   document_file  mimes:pdf,jpg,jpeg,png,docx,xlsx max:10240
//                    (dùng chung cho tab Tài liệu của HR lẫn "Hồ sơ của tôi")
//   transferDecision StoreEmployeeTransferRequest   decision_file  mimes:pdf                     max:10240
//   leaveEvidence    StoreLeaveRequest              evidence_file  mimes:jpg,jpeg,png,pdf        max:5120
// (max của Laravel tính bằng KB: 5120 = 5MB, 10240 = 10MB.) Ảnh đại diện KHÔNG
// dùng component này — rule ở EmployeeController::AVATAR_RULES (jpg/jpeg/png/
// webp, max:2048), giao diện tự đổi ảnh nằm thẳng trong MyProfile.vue
// (AVATAR_TYPES/AVATAR_MAX_BYTES — đổi rule thì đổi cả 2 bên).
export const UPLOAD_LIMITS = {
    contract: { maxSizeMb: 5, formats: "PDF", accept: ".pdf" },
    document: {
        maxSizeMb: 10,
        formats: "PDF, Word (.docx), Excel (.xlsx), JPG, PNG",
        accept: ".pdf,.jpg,.jpeg,.png,.docx,.xlsx",
    },
    transferDecision: { maxSizeMb: 10, formats: "PDF", accept: ".pdf" },
    leaveEvidence: {
        maxSizeMb: 5,
        formats: "JPG, PNG, PDF",
        accept: ".pdf,.jpg,.jpeg,.png",
    },
};
</script>

<script setup>
import { computed, defineAsyncComponent, ref } from "vue";

// Ô chọn tệp dùng chung: bọc v-file-input của Vuetify, tự lấy `accept` và dòng
// gợi ý "Định dạng ..., tối đa ...MB" từ 1 mục UPLOAD_LIMITS. Dùng
// persistent-hint để dòng gợi ý LUÔN hiện — kể cả sau khi đã chọn tệp (khác
// placeholder: biến mất ngay khi chọn), và khi có lỗi 422 thì Vuetify tự thay
// bằng câu lỗi của Backend (đã nêu sẵn giới hạn), không hiện chồng 2 dòng.
//
// Giống SearchSelect.vue: mọi thuộc tính còn lại (error-messages, rules,
// disabled...) rơi thẳng xuống v-file-input qua $attrs, không khai báo lại.
// v-file-input trả về MẢNG (kể cả khi không multiple) — nơi gọi vẫn tự lấy
// phần tử đầu như trước, component này không đổi kiểu giá trị.
//
// Riêng modelValue thì PHẢI khai báo (không để rơi qua $attrs): component cần
// đọc được tệp vừa chọn để dựng bản xem trước, nhưng vẫn trả nguyên giá trị
// v-file-input đưa ra nên nơi gọi không phải sửa gì.
const props = defineProps({
    limit: {
        type: Object,
        required: true,
    },
    modelValue: {
        type: [Array, File],
        default: null,
    },
});

const emit = defineEmits(["update:modelValue"]);

// Component có 2 node gốc (ô chọn tệp + dialog xem trước) nên Vue KHÔNG tự rót
// $attrs xuống nữa — phải tắt rồi tự v-bind vào đúng ô chọn tệp. Đặt
// v-bind="$attrs" SAU các thuộc tính mặc định để nơi gọi ghi đè được (ví dụ
// EmployeeForm.vue truyền hint riêng thay cho dòng "Định dạng ..., tối đa ...").
defineOptions({ inheritAttrs: false });

const hint = computed(
    () =>
        `Định dạng ${props.limit.formats}, tối đa ${props.limit.maxSizeMb}MB`,
);

// Xem trước TRƯỚC khi bấm Lưu: upload nhầm bản scan của người khác là lỗi thao
// tác rất dễ xảy ra, mà sửa sau thì phải thay tệp + ghi lý do (xem
// EmployeeContractService::fillMissing). Mở ngay tại máy, chưa gửi byte nào lên
// server. Tài liệu yêu cầu §3.2 mục 2 "preview file PDF trực tiếp" và kế hoạch
// Ngày 29 — ở đây dùng chung cho mọi ô chọn tệp, không riêng hợp đồng.
const FilePreviewDialog = defineAsyncComponent(
    () => import("./FilePreviewDialog.vue"),
);

const previewOpen = ref(false);

// v-file-input trả mảng khi multiple, còn lại trả thẳng File — nhận cả 2.
const pickedFile = computed(() => {
    const value = Array.isArray(props.modelValue)
        ? props.modelValue[0]
        : props.modelValue;

    return value instanceof File ? value : null;
});
</script>

<template>
    <v-file-input
        variant="outlined"
        density="comfortable"
        rounded="lg"
        prepend-icon=""
        prepend-inner-icon="mdi-paperclip"
        placeholder="Chưa chọn tệp"
        persistent-placeholder
        persistent-hint
        :accept="limit.accept"
        :hint="hint"
        :model-value="modelValue"
        v-bind="$attrs"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <!-- Nút xem trước nằm TRONG ô chọn tệp: ở đâu có ô upload là ở đó có
             nút, không phụ thuộc vào bố cục của từng dialog gọi tới.
             @click.stop: ô v-file-input bắt click để mở hộp chọn tệp của hệ
             điều hành, không chặn thì bấm "xem trước" lại nhảy ra hộp chọn tệp. -->
        <template v-if="pickedFile" #append-inner>
            <v-tooltip text="Xem trước tệp vừa chọn" location="top">
                <template #activator="{ props: tooltipProps }">
                    <v-btn
                        v-bind="tooltipProps"
                        icon="mdi-eye-outline"
                        variant="text"
                        density="comfortable"
                        size="small"
                        color="primary"
                        aria-label="Xem trước tệp vừa chọn"
                        @click.stop.prevent="previewOpen = true"
                    />
                </template>
            </v-tooltip>
        </template>
    </v-file-input>

    <FilePreviewDialog
        v-if="pickedFile"
        v-model="previewOpen"
        :file="pickedFile"
        :file-name="pickedFile.name"
    />
</template>
