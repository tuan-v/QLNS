<script setup>
import { onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from './components/layout/AppLayout.vue';
import AppLoadingBar from './components/common/AppLoadingBar.vue';
import AppToast from './components/common/AppToast.vue';
import { useAuthStore } from './stores/authStore';

const route = useRoute();
const auth = useAuthStore();

onMounted(() => {
    if (auth.accessToken && !auth.user) {
        auth.fetchMe();
    }
});
</script>

<template>
  <v-app>
    <AppLoadingBar />
    <AppLayout v-if="route.meta.layout !== 'blank'">
      <router-view />
    </AppLayout>
    <router-view v-else />
    <AppToast />
  </v-app>
</template>
