<template>
    <div>
        <PageHeader
            title="Lịch sử chấm công"
            subtitle="Lịch sử chấm công của bạn theo từng ca — chỉ xem, không chỉnh sửa."
        />

        <AttendanceHistoryPanel :employee-id="null" read-only />
    </div>
</template>

<script setup>
// Trang tự phục vụ — nhân viên tự xem lịch sử chấm công CHÍNH MÌNH, chỉ
// hiển thị. Toàn bộ nghiệp vụ (bộ lọc, thẻ thống kê, bảng) nằm ở
// AttendanceHistoryPanel.vue — component đó còn được tái dùng cho phía
// Admin (tab "Chấm công" trong EmployeeDetail.vue, employeeId khác null,
// readOnly=false).
import PageHeader from "../../components/common/PageHeader.vue";
import AttendanceHistoryPanel from "./AttendanceHistoryPanel.vue";
</script>
