<script>
// Bỏ dấu tiếng Việt để gõ không dấu vẫn tìm ra: "ha noi" -> "Hà Nội",
// "phuong dong da" -> "Phường Đống Đa".
//   .normalize("NFD")        tách nguyên âm và dấu thành 2 ký tự riêng
//   [̀-ͯ]          xóa toàn bộ dấu vừa tách ra
//   đ -> d                   chữ "đ" KHÔNG tách ra được (là 1 ký tự độc lập
//                            U+0111), nên phải đổi riêng, nếu không "da nang"
//                            sẽ không khớp "Đà Nẵng"
export function normalizeVietnamese(text) {
    return String(text ?? "")
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .toLowerCase()
        .replace(/đ/g, "d");
}
</script>

<script setup>
// Ô chọn có tìm kiếm, dùng chung. Bọc v-autocomplete của Vuetify thay vì viết
// lại từ đầu — v-autocomplete đã lo sẵn phần khó: lọc theo từ khóa, cuộn ảo
// (danh sách 3321 xã/phường vẫn mượt), điều hướng bàn phím, ARIA.
//
// Component này chỉ thêm đúng hai thứ Vuetify không có:
//   1. Tìm kiếm không dấu (xem normalizeVietnamese ở trên)
//   2. Bộ mặc định về giao diện + chữ tiếng Việt, để mọi ô chọn trong dự án
//      trông giống nhau mà không phải lặp lại 5 dòng prop ở từng chỗ
//
// Cố ý KHÔNG khai báo prop modelValue: mọi thuộc tính (items, v-model,
// :model-value, @update:model-value, rules, loading, disabled, multiple,
// chips, error-messages...) đều rơi thẳng xuống v-autocomplete qua $attrs.
// Nhờ vậy dùng được cả kiểu v-model lẫn kiểu :model-value + @update thủ công
// mà không phải bọc lại từng prop một.
defineOptions({
    inheritAttrs: true,
});

function filterVietnamese(value, query) {
    if (!query) {
        return true;
    }

    return normalizeVietnamese(value).includes(normalizeVietnamese(query));
}

</script>

<template>
    <v-autocomplete
        variant="outlined"
        density="comfortable"
        rounded="lg"
        persistent-placeholder
        placeholder="Chưa chọn"
        no-data-text="Không tìm thấy kết quả"
        :custom-filter="filterVietnamese"
        :menu-props="{ maxHeight: 320 }"
        v-bind="$attrs"
    />
</template>
