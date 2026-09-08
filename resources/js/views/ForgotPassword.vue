<template>
  <div
    style="
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
      background:
        radial-gradient(circle at 12% 8%, rgba(117, 117, 219, 0.3), transparent 36rem),
        rgb(var(--v-theme-background));
    "
  >
    <div style="width: min(100%, 26rem); display: flex; flex-direction: column; gap: 1.75rem;">
      <div style="display: flex; flex-direction: column; align-items: center; gap: 0.85rem; text-align: center;">
        <div
          style="
            width: 3rem;
            height: 3rem;
            border-radius: 0.875rem;
            background: rgb(var(--v-theme-primary));
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0.75rem 1.5rem rgba(117, 117, 219, 0.35);
          "
        >
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="5" r="2.5"></circle>
            <circle cx="5" cy="18" r="2.5"></circle>
            <circle cx="19" cy="18" r="2.5"></circle>
            <path d="M12 7.5v4M12 11.5 6.5 16M12 11.5l5.5 4.5"></path>
          </svg>
        </div>
        <div>
          <div style="font-size: 1.15rem; font-weight: 700; letter-spacing: -0.01em;">QLNS</div>
          <div style="margin-top: 0.15rem; font-size: 0.8rem; opacity: 0.65;">Hệ thống Quản lý Nhân sự</div>
        </div>
      </div>

      <v-card style="border-radius: 1.5rem;" class="pa-6 glass-panel">
        <v-card-title class="text-h5 font-weight-bold px-0">Quên mật khẩu</v-card-title>
        <v-card-subtitle class="px-0 text-wrap" style="opacity: 0.75;">
          Nhập email đã đăng ký — chúng tôi sẽ gửi liên kết đặt lại mật khẩu.
        </v-card-subtitle>

        <v-card-text v-if="!sent" class="px-0" style="display: flex; flex-direction: column; gap: 0.35rem;">
          <v-text-field
            v-model="email"
            label="Email"
            variant="outlined"
            type="email"
            hide-details
            density="comfortable"
            :error-messages="emailError"
            @keyup.enter="handleSubmit"
          />

          <v-btn
            color="primary"
            block
            size="large"
            class="mt-3"
            :loading="loading"
            @click="handleSubmit"
          >
            Gửi liên kết đặt lại
          </v-btn>

          <router-link
            to="/login"
            class="text-caption d-block text-center mt-2"
            style="color: rgb(var(--v-theme-primary));"
          >
            Quay lại đăng nhập
          </router-link>
        </v-card-text>

        <v-card-text v-else class="px-0">
          <v-alert type="success" variant="tonal" icon="mdi-email-check-outline">
            Nếu email tồn tại trong hệ thống, liên kết đặt lại mật khẩu đã được gửi. Vui lòng kiểm tra hộp thư.
          </v-alert>
          <router-link
            to="/login"
            class="text-caption d-block text-center mt-4"
            style="color: rgb(var(--v-theme-primary));"
          >
            Quay lại đăng nhập
          </router-link>
        </v-card-text>
      </v-card>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import authService from "../services/authService";

const email = ref("");
const emailError = ref("");
const loading = ref(false);
// Luôn hiện thông báo thành công sau khi gửi, kể cả khi backend trả về "email
// không tồn tại" cũng KHÔNG hiển thị khác đi — tránh lộ thông tin tài khoản
// nào đang tồn tại trong hệ thống (đúng như cách backend đã cố tình trả về
// cùng 1 message chung cho cả 2 trường hợp).
const sent = ref(false);

async function handleSubmit() {
    emailError.value = "";
    loading.value = true;
    try {
        await authService.forgotPassword(email.value);
        sent.value = true;
    } catch (error) {
        const data = error.response?.data;
        emailError.value = data?.errors?.email?.[0] ?? "Có lỗi xảy ra, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}
</script>
