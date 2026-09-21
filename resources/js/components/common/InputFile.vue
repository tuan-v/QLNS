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
// (max của Laravel tính bằng KB: 5120 = 5MB, 10240 = 10MB.) Ảnh đại diện có rule
// 2MB ở EmployeeController::uploadAvatar nhưng CHƯA có giao diện tải lên nào.
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
import { computed } from "vue";

// Ô chọn tệp dùng chung: bọc v-file-input của Vuetify, tự lấy `accept` và dòng
// gợi ý "Định dạng ..., tối đa ...MB" từ 1 mục UPLOAD_LIMITS. Dùng
// persistent-hint để dòng gợi ý LUÔN hiện — kể cả sau khi đã chọn tệp (khác
// placeholder: biến mất ngay khi chọn), và khi có lỗi 422 thì Vuetify tự thay
// bằng câu lỗi của Backend (đã nêu sẵn giới hạn), không hiện chồng 2 dòng.
//
// Giống SearchSelect.vue: mọi thuộc tính còn lại (v-model, error-messages,
// disabled...) rơi thẳng xuống v-file-input qua $attrs, không khai báo lại.
// v-file-input trả về MẢNG (kể cả khi không multiple) — nơi gọi vẫn tự lấy
// phần tử đầu như trước, component này không đổi kiểu giá trị.
const props = defineProps({
    limit: {
        type: Object,
        required: true,
    },
});

const hint = computed(
    () =>
        `Định dạng ${props.limit.formats}, tối đa ${props.limit.maxSizeMb}MB`,
);
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
    />
</template>
