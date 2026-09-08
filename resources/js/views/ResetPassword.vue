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
        <template v-if="!token">
          <v-card-title class="text-h5 font-weight-bold px-0">Liên kết không hợp lệ</v-card-title>
          <v-card-text class="px-0">
            <v-alert type="error" variant="tonal" icon="mdi-alert-circle-outline">
              Thiếu mã token trong liên kết. Vui lòng mở lại email hoặc yêu cầu gửi liên kết mới.
            </v-alert>
            <router-link
              to="/forgot-password"
              class="text-caption d-block text-center mt-4"
              style="color: rgb(var(--v-theme-primary));"
            >
              Yêu cầu liên kết mới
            </router-link>
          </v-card-text>
        </template>

        <template v-else>
          <v-card-title class="text-h5 font-weight-bold px-0">Đặt lại mật khẩu</v-card-title>
          <v-card-subtitle v-if="email" class="px-0 text-wrap" style="opacity: 0.75;">
            Tài khoản: {{ email }}
          </v-card-subtitle>

          <v-card-text class="px-0" style="display: flex; flex-direction: column; gap: 0.35rem;">
            <div>
              <v-text-field
                v-model="password"
                label="Mật khẩu mới"
                variant="outlined"
                type="password"
                hide-details
                density="comfortable"
              />
              <div v-if="passwordError" class="text-error text-caption mt-1">{{ passwordError }}</div>
            </div>

            <div>
              <v-text-field
                v-model="passwordConfirmation"
                label="Xác nhận mật khẩu mới"
                variant="outlined"
                type="password"
                hide-details
                density="comfortable"
                @keyup.enter="handleSubmit"
              />
            </div>

            <v-alert v-if="generalError" type="error" variant="tonal" density="compact" class="mt-2">
              {{ generalError }}
            </v-alert>

            <v-btn
              color="primary"
              block
              size="large"
              class="mt-3"
              :loading="loading"
              @click="handleSubmit"
            >
              Đặt lại mật khẩu
            </v-btn>
          </v-card-text>
        </template>
      </v-card>
    </div>
  </div>
</template>

<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import authService from "../services/authService";
import { useToastStore } from "../stores/useToastStore";

const route = useRoute();
const router = useRouter();
const toast = useToastStore();

const token = ref(route.query.token ?? "");
const email = ref(route.query.email ?? "");

const password = ref("");
const passwordConfirmation = ref("");
const passwordError = ref("");
const generalError = ref("");
const loading = ref(false);

async function handleSubmit() {
    passwordError.value = "";
    generalError.value = "";
    loading.value = true;
    try {
        await authService.resetPassword(token.value, password.value, passwordConfirmation.value);
        toast.success("Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.");
        router.push("/login");
    } catch (error) {
        const status = error.response?.status;
        const data = error.response?.data;

        if (status === 422 && data?.errors) {
            // Lỗi "token" (hết hạn/không hợp lệ/đã dùng) từ backend hiện ở khối
            // cảnh báo chung, vì trang này không có ô nhập "token" để gắn lỗi vào.
            passwordError.value = data.errors.password?.[0] ?? "";
            generalError.value = data.errors.token?.[0] ?? "";
        } else {
            generalError.value = data?.message ?? "Có lỗi xảy ra, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
