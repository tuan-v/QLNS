<script setup>
import { useRoute } from "vue-router";
const route = useRoute();
</script>

<template>
    <v-breadcrumbs
        :items="[
            { title: 'Trang chủ', to: '/' },
            { title: route.name, disabled: true },
        ]"
        density="compact"
        class="pa-0"
    />
</template>
