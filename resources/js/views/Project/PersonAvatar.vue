<template>
    <v-tooltip :text="tooltip" location="top" :disabled="!tooltip">
        <template #activator="{ props: tipProps }">
            <v-avatar
                v-bind="tipProps"
                :size="size"
                :color="person?.avatar_url ? undefined : person ? avatarColor(person.full_name) : 'grey-lighten-2'"
                class="person-avatar"
                :class="{ 'person-avatar--ring': ring }"
            >
                <v-img v-if="person?.avatar_url" :src="person.avatar_url" cover />
                <span
                    v-else-if="person"
                    class="text-white font-weight-medium"
                    :style="{ fontSize: `${Math.round(size * 0.38)}px` }"
                >
                    {{ initials(person.full_name) }}
                </span>
                <v-icon v-else icon="mdi-account-question-outline" :size="Math.round(size * 0.55)" color="grey" />
            </v-avatar>
        </template>
    </v-tooltip>
</template>

<script setup>
import { avatarColor, initials } from "../../composables/avatar";

// Avatar 1 nhân viên trong module Dự án (ảnh thật hoặc chữ cái đầu; null = "chưa giao").
defineProps({
    person: { type: Object, default: null },
    size: { type: Number, default: 32 },
    tooltip: { type: String, default: "" },
    // Viền trắng — dùng khi xếp chồng nhiều avatar.
    ring: { type: Boolean, default: false },
});
</script>

<style scoped>
.person-avatar--ring {
    box-shadow: 0 0 0 2px rgb(var(--v-theme-surface));
}
</style>
