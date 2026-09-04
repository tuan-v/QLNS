<script setup>
import StatCards from '../components/dashboard/StatCards.vue';
import PageHeader from '../components/common/PageHeader.vue';

const stats = [
    { label: 'Tổng số nhân viên', value: '128', icon: 'mdi-account-group-outline', color: 'primary' },
    { label: 'Nhân viên mới tháng này', value: '6', icon: 'mdi-account-plus-outline', color: 'primary' },
    { label: 'Đơn nghỉ phép chờ duyệt', value: '9', icon: 'mdi-calendar-alert-outline', color: 'warning' },
    { label: 'Tỷ lệ đi làm hôm nay', value: '96%', icon: 'mdi-check-circle-outline', color: 'success' },
];
</script>

<template>
  <div>
    <PageHeader
      title="Tổng quan"
      subtitle="Số liệu nhân sự cập nhật tới hôm nay."
    />

    <StatCards :stats="stats" />

    <v-alert type="info" variant="tonal" density="compact" class="mt-4" icon="mdi-information-outline">
      Đây là dữ liệu minh họa. Dashboard thật (biểu đồ, số liệu động) sẽ được xây ở giai đoạn Báo cáo &amp; Thống kê sau này.
    </v-alert>
  </div>
</template>
