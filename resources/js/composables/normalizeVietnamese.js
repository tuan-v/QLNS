// Bỏ dấu tiếng Việt để gõ không dấu vẫn tìm ra: "ha noi" -> "Hà Nội",
// "phuong dong da" -> "Phường Đống Đa".
//   .normalize("NFD")        tách nguyên âm và dấu thành 2 ký tự riêng
//   [̀-ͯ]          xóa toàn bộ dấu vừa tách ra
//   đ -> d                   chữ "đ" KHÔNG tách ra được (là 1 ký tự độc lập
//                            U+0111), nên phải đổi riêng, nếu không "da nang"
//                            sẽ không khớp "Đà Nẵng"
//
// Để ở module riêng (thay vì export từ SearchSelect.vue như trước) để chỗ nào
// chỉ cần lọc chữ — ví dụ ô tìm quyền nhanh trong RolePermissionsDialog.vue —
// không phải tải kèm cả component v-autocomplete chỉ vì 5 dòng này.
export function normalizeVietnamese(text) {
    return String(text ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/đ/g, "d");
}
