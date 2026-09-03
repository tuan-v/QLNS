<template>
  <v-navigation-drawer
    v-model:rail="rail"
    rail-width="80"
    width="264"
    expand-on-hover
    permanent
    class="border-e"
  >
    <div class="d-flex align-center ga-3 pa-4 border-b" style="min-height: 64px;">
      <div
        style="
          width: 36px;
          height: 36px;
          border-radius: 10px;
          background: rgb(var(--v-theme-primary));
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
        "
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="5" r="2.5"></circle>
          <circle cx="5" cy="18" r="2.5"></circle>
          <circle cx="19" cy="18" r="2.5"></circle>
          <path d="M12 7.5v4M12 11.5 6.5 16M12 11.5l5.5 4.5"></path>
        </svg>
      </div>
      <div v-if="!rail" style="overflow: hidden;">
        <div class="text-subtitle-2 font-weight-bold text-no-wrap">QLNS</div>
        <div class="text-caption text-no-wrap" style="opacity: 0.6;">Quản lý Nhân sự</div>
      </div>
    </div>

    <v-list nav density="compact" color="primary" class="px-2 pt-2">
      <v-list-item prepend-icon="mdi-view-dashboard-outline" title="Tổng quan" to="/" rounded="lg" />

      <v-list-subheader v-if="!rail">NHÂN SỰ</v-list-subheader>
      <v-list-item prepend-icon="mdi-account-group-outline" title="Nhân viên" rounded="lg" disabled />
      <v-list-item prepend-icon="mdi-office-building-outline" title="Phòng ban" rounded="lg" disabled />

      <v-list-subheader v-if="!rail">CHẤM CÔNG &amp; NGHỈ PHÉP</v-list-subheader>
      <v-list-item prepend-icon="mdi-calendar-check-outline" title="Chấm công" rounded="lg" disabled />
      <v-list-item prepend-icon="mdi-calendar-blank-outline" title="Nghỉ phép" rounded="lg" disabled />

      <v-list-subheader v-if="!rail">LƯƠNG &amp; BÁO CÁO</v-list-subheader>
      <v-list-item prepend-icon="mdi-wallet-outline" title="Bảng lương" rounded="lg" disabled />
      <v-list-item prepend-icon="mdi-chart-bar" title="Báo cáo" rounded="lg" disabled />

      <v-list-subheader v-if="!rail">HỆ THỐNG</v-list-subheader>
      <v-list-item prepend-icon="mdi-shield-account-outline" title="Vai trò &amp; Phân quyền" rounded="lg" disabled />
      <v-list-item prepend-icon="mdi-history" title="Nhật ký hoạt động" rounded="lg" disabled />
    </v-list>

    <template #append>
      <v-divider class="mb-2" />
      <v-list nav density="compact" class="px-2 pb-2">
        <v-list-item prepend-icon="mdi-help-circle-outline" title="Hướng dẫn sử dụng" rounded="lg" disabled />
      </v-list>
    </template>
  </v-navigation-drawer>
</template>

<script setup>
import { ref } from 'vue';

const rail = ref(true);
</script>
