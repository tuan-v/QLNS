<script setup>
import AppHeader from "./AppHeader.vue";
import AppSidebar from "./AppSidebar.vue";
import AppBreadcrumbs from "./AppBreadcrumbs.vue";
</script>

<template>
    <AppHeader />
    <AppSidebar />
    <v-main style="background: rgb(var(--v-theme-background))">
        <v-container fluid class="pa-6">
            <AppBreadcrumbs class="mb-4" />
            <slot />
        </v-container>
    </v-main>
</template>
