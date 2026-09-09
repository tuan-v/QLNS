<template>
    <div
        style="
            min-height: 60vh;
            display: flex;
            align-items: center;
            justify-content: center;
        "
    >
        <v-progress-circular v-if="loading" indeterminate size="32" />

        <v-alert
            v-else-if="error"
            type="warning"
            variant="tonal"
            density="comfortable"
            max-width="480"
            icon="mdi-information-outline"
        >
            {{ error }}
        </v-alert>
    </div>
</template>

<script setup>
// Trang trung gian: điều hướng thẳng tới đúng hồ sơ nhân viên của CHÍNH
// người đang đăng nhập (dùng lại nguyên EmployeeDetail.vue — mọi tab Sơ yếu
// lý lịch/Hợp đồng/Tài liệu/Luân chuyển/Ca làm việc đã có sẵn, không cần
// dựng lại giao diện riêng). Nút Sửa/Xóa trong các tab đó tự ẩn nếu tài
// khoản không có employee.update, nên xem hồ sơ mình luôn ở dạng chỉ đọc
// đúng như mong muốn — không cần thêm cờ "readonly" nào riêng.
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import employeeService from "../services/employeeService";

const router = useRouter();
const loading = ref(true);
const error = ref("");

onMounted(async () => {
    try {
        const response = await employeeService.me();
        router.replace({
            name: "employee-detail",
            params: { id: response.data.data.id },
        });
    } catch (e) {
        error.value =
            e.response?.status === 404
                ? "Tài khoản này chưa được liên kết với hồ sơ nhân viên nào. Liên hệ phòng Nhân sự để được hỗ trợ."
                : "Không thể tải hồ sơ cá nhân, vui lòng thử lại.";
        loading.value = false;
    }
});
</script>
