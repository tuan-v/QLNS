<script setup>
import { ref } from "vue";
import { useDisplay, useTheme } from "vuetify";
import { useAuthStore } from "../../stores/authStore";
import { useRoute, useRouter } from "vue-router";
import NotificationCenter from "./NotificationCenter.vue";
import QuickSearch from "./QuickSearch.vue";

const theme = useTheme();
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const { mobile } = useDisplay();

const quickSearch = ref(null);

defineEmits(["toggle-sidebar"]);

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
    <v-app-bar flat class="border-b qlns-header">
        <v-btn
            icon
            variant="text"
            class="qlns-icon-btn ml-4"
            aria-label="Thu gọn / mở menu"
            @click="$emit('toggle-sidebar')"
        >
            <v-icon size="20">mdi-menu</v-icon>
        </v-btn>
        <span class="text-subtitle-1 font-weight-medium ml-4 text-truncate">{{
            route.meta?.title ?? "Tổng quan"
        }}</span>
        <v-spacer />

        <!-- Quick Search (Ctrl+K) — Phase 1: chỉ tìm CHỨC NĂNG (danh sách cố
             định trong QuickSearch.vue, không gọi API), xem CODE_MAP. Desktop
             hiện cả ô gợi ý placeholder + chip "Ctrl K"; màn hẹp chỉ còn icon
             kính lúp cho gọn, cùng logic mở (quickSearch.open()). -->
        <v-text-field
            v-if="!mobile"
            readonly
            hide-details
            density="compact"
            variant="solo-filled"
            flat
            placeholder="Tìm kiếm nhân viên, chức năng..."
            prepend-inner-icon="mdi-magnify"
            style="max-width: 340px; cursor: pointer"
            class="mx-4"
            @click="quickSearch?.open()"
        >
            <template #append-inner>
                <v-chip size="x-small" variant="outlined" style="opacity: 0.6">Ctrl K</v-chip>
            </template>
        </v-text-field>
        <v-btn
            v-else
            icon
            variant="text"
            class="qlns-icon-btn"
            aria-label="Tìm kiếm"
            @click="quickSearch?.open()"
        >
            <v-icon size="20">mdi-magnify</v-icon>
        </v-btn>

        <v-spacer />

        <QuickSearch ref="quickSearch" />

        <v-btn
            icon
            variant="text"
            class="qlns-icon-btn mr-2"
            :aria-label="theme.global.current.value.dark ? 'Chế độ sáng' : 'Chế độ tối'"
            @click="toggleTheme"
        >
            <v-icon size="20">{{
                theme.global.current.value.dark
                    ? "mdi-weather-sunny"
                    : "mdi-weather-night"
            }}</v-icon>
        </v-btn>

        <NotificationCenter />

        <v-menu>
            <template #activator="{ props }">
                <v-btn
                    v-bind="props"
                    variant="text"
                    class="text-none ml-2 mr-3"
                    rounded="pill"
                >
                    <v-avatar color="primary" size="36" class="mr-2">
                        <v-img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" cover />
                        <span v-else class="text-body-2 font-weight-medium">{{
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
                        <span class="text-caption text-medium-emphasis">{{
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
