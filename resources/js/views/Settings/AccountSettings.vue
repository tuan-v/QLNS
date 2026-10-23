<template>
    <div>
        <PageHeader title="Cài đặt tài khoản" subtitle="Thông tin đăng nhập và bảo mật tài khoản của bạn." />

        <v-sheet class="border rounded-lg pa-5 glass-panel" color="transparent" max-width="640">
            <FormSection
                title="Đổi mật khẩu"
                icon="mdi-lock-reset"
                description="Đổi xong, các máy khác đang đăng nhập tài khoản này sẽ phải đăng nhập lại bằng mật khẩu mới."
            >
                <div class="text-body-2 mb-4">
                    Tài khoản: <strong>{{ auth.user?.email }}</strong>
                </div>

                <v-form @submit.prevent="submit">
                    <div v-for="field in FIELDS" :key="field.key" class="mb-3">
                        <div class="text-body-2 font-weight-medium mb-1">
                            {{ field.label }} <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form[field.key]"
                            :type="visible[field.key] ? 'text' : 'password'"
                            :autocomplete="field.autocomplete"
                            :hint="field.hint"
                            :persistent-hint="Boolean(field.hint)"
                            :error-messages="errors[field.key]"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :append-inner-icon="visible[field.key] ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
                            @click:append-inner="visible[field.key] = !visible[field.key]"
                            @update:model-value="errors[field.key] = ''"
                        />
                    </div>

                    <div class="d-flex justify-end mt-4">
                        <v-btn type="submit" color="primary" variant="flat" :loading="saving" :disabled="!filled">
                            Đổi mật khẩu
                        </v-btn>
                    </div>
                </v-form>
            </FormSection>
        </v-sheet>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from "vue";
import PageHeader from "../../components/common/PageHeader.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useAuthStore } from "../../stores/authStore";
import { useToastStore } from "../../stores/useToastStore";

// Trang "Cài đặt" trong menu tài khoản (góc phải trên) — ai đăng nhập cũng vào được, khác "Cài đặt hệ thống".
const FIELDS = [
    { key: "current_password", label: "Mật khẩu hiện tại", autocomplete: "current-password" },
    {
        key: "password",
        label: "Mật khẩu mới",
        autocomplete: "new-password",
        hint: "Ít nhất 8 ký tự, khác mật khẩu hiện tại.",
    },
    { key: "password_confirmation", label: "Nhập lại mật khẩu mới", autocomplete: "new-password" },
];

const auth = useAuthStore();
const toast = useToastStore();
const emptyForm = () => ({ current_password: "", password: "", password_confirmation: "" });
const form = reactive(emptyForm());
const errors = reactive({});
const visible = reactive({});
const saving = ref(false);

const filled = computed(() => FIELDS.every((field) => form[field.key]));

async function submit() {
    if (!filled.value) return;
    if (form.password !== form.password_confirmation) {
        errors.password_confirmation = "Xác nhận mật khẩu không khớp.";
        return;
    }

    saving.value = true;
    try {
        await auth.changePassword({ ...form });
        Object.assign(form, emptyForm());
        toast.success("Đã đổi mật khẩu. Các máy khác sẽ phải đăng nhập lại.");
    } catch (e) {
        if (e.response?.status === 422) {
            for (const [key, messages] of Object.entries(e.response.data.errors ?? {})) {
                errors[key] = messages[0];
            }
        } else {
            toast.error(e.response?.data?.message ?? "Không đổi được mật khẩu, vui lòng thử lại.");
        }
    } finally {
        saving.value = false;
    }
}
</script>
