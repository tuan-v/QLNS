<script setup>
import { useTheme } from "vuetify";
import { useAuthStore } from "../../stores/authStore";
import { useRouter } from "vue-router";

const theme = useTheme();
const auth = useAuthStore();
const router = useRouter();

function toggleTheme() {
    theme.global.name.value = theme.global.current.value.dark
        ? "qlnsLight"
        : "qlnsDark";
}

async function handleLogout() {
    await auth.logout();
    router.push("/login");
}
</script>

<template>
    <v-app-bar flat class="border-b">
        <v-app-bar-nav-icon />
        <v-spacer />

        <v-btn icon variant="text" @click="toggleTheme">
            <v-icon size="20">{{
                theme.global.current.value.dark
                    ? "mdi-weather-sunny"
                    : "mdi-weather-night"
            }}</v-icon>
        </v-btn>

        <v-btn icon variant="text">
            <v-icon size="20">mdi-bell-outline</v-icon>
        </v-btn>

        <v-menu>
            <template #activator="{ props }">
                <v-btn
                    v-bind="props"
                    variant="text"
                    class="text-none ml-1"
                    rounded="lg"
                >
                    <v-avatar color="primary" size="30" class="mr-2">
                        <span class="text-caption font-weight-bold">{{
                            auth.user?.user_name?.charAt(0)
                        }}</span>
                    </v-avatar>
                    <span
                        class="d-none d-sm-flex flex-column align-start"
                        style="line-height: 1.2"
                    >
                        <span class="text-body-2 font-weight-medium">{{
                            auth.user?.user_name
                        }}</span>
                        <span class="text-caption" style="opacity: 0.6">{{
                            auth.user?.roles?.[0]
                        }}</span>
                    </span>
                    <v-icon end size="18">mdi-chevron-down</v-icon>
                </v-btn>
            </template>

            <v-list width="220" density="comfortable">
                <v-list-item
                    :title="auth.user?.user_name"
                    :subtitle="auth.user?.email"
                />
                <v-divider class="my-1" />
                <v-list-item
                    prepend-icon="mdi-cog-outline"
                    title="Cài đặt"
                    rounded="lg"
                    disabled
                />
                <v-list-item
                    prepend-icon="mdi-logout"
                    title="Đăng xuất"
                    rounded="lg"
                    class="text-error"
                    @click="handleLogout"
                />
            </v-list>
        </v-menu>
    </v-app-bar>
</template>
