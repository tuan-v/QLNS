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

      <v-card
        style="border-radius: 1.5rem; backdrop-filter: blur(12px); background: rgba(30, 41, 68, 0.76);"
        class="pa-6"
      >
        <v-card-title class="text-h5 font-weight-bold px-0">Đăng nhập</v-card-title>
        <v-card-subtitle class="px-0 text-wrap" style="opacity: 0.75;">
          Sử dụng tài khoản được phòng Nhân sự cấp để truy cập hệ thống.
        </v-card-subtitle>

        <v-card-text class="px-0" style="display: flex; flex-direction: column; gap: 0.35rem;">
          <div>
            <v-text-field v-model="email" label="Email" variant="outlined" type="email" hide-details density="comfortable">
              <template #prepend-inner>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                  <path d="m3 7 9 6 9-6"></path>
                </svg>
              </template>
            </v-text-field>
            <div v-if="emailError" class="text-error text-caption mt-1">{{ emailError }}</div>
          </div>

          <div>
            <v-text-field
              v-model="password"
              label="Mật khẩu"
              variant="outlined"
              :type="showPassword ? 'text' : 'password'"
              hide-details
              density="comfortable"
            >
              <template #prepend-inner>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                  <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                </svg>
              </template>
              <template #append-inner>
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="rgba(255,255,255,0.5)"
                  stroke-width="1.75"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  style="cursor: pointer;"
                  @click="showPassword = !showPassword"
                >
                  <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </template>
            </v-text-field>
            <div v-if="passwordError" class="text-error text-caption mt-1">{{ passwordError }}</div>
          </div>

          <div class="d-flex align-center justify-space-between mt-2">
            <v-checkbox v-model="remember" label="Ghi nhớ đăng nhập" density="compact" hide-details />
            <a href="#" class="text-caption" style="color: rgb(var(--v-theme-primary));">Quên mật khẩu?</a>
          </div>

          <v-btn color="primary" block size="large" class="mt-3" @click="handleLogin">
            Đăng nhập
          </v-btn>
        </v-card-text>
      </v-card>

      <p style="text-align: center; font-size: 0.75rem; opacity: 0.55; margin: 0;">
        © 2026 QLNS — Nội bộ công ty. Không hỗ trợ tự đăng ký tài khoản.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/authStore';

const router = useRouter();
const auth = useAuthStore();

const email = ref('');
const password = ref('');
const remember = ref(false);
const emailError = ref('');
const passwordError = ref('');
const showPassword = ref(false);

async function handleLogin() {
    emailError.value = '';
    passwordError.value = '';
    try {
        await auth.login(email.value, password.value);
        router.push('/'); // hoặc route Dashboard thật khi có
    } catch (error) {
        const status = error.response?.status;
        const data = error.response?.data;

        if (status === 422 && data?.errors) {
            emailError.value = data.errors.email?.[0] ?? '';
            passwordError.value = data.errors.password?.[0] ?? '';
        } else if (status === 401) {
            passwordError.value = data?.message ?? 'Email hoặc mật khẩu không đúng.';
        } else {
            passwordError.value = 'Có lỗi xảy ra, vui lòng thử lại.';
        }
    }
}
</script>
