<template>
    <div class="avatar-stack d-inline-flex align-center">
        <PersonAvatar
            v-for="person in shown"
            :key="person.id"
            :person="person"
            :size="size"
            :tooltip="person.full_name"
            ring
            class="avatar-stack__item"
        />
        <v-avatar v-if="rest > 0" :size="size" color="surface-variant" class="avatar-stack__item avatar-stack__more">
            <span class="text-caption font-weight-medium">+{{ rest }}</span>
        </v-avatar>
    </div>
</template>

<script setup>
import { computed } from "vue";
import PersonAvatar from "./PersonAvatar.vue";

// Dãy avatar xếp chồng (thành viên dự án) — quá `max` người thì hiện "+N".
const props = defineProps({
    people: { type: Array, default: () => [] },
    max: { type: Number, default: 4 },
    size: { type: Number, default: 28 },
});

const shown = computed(() => props.people.slice(0, props.max));
const rest = computed(() => props.people.length - shown.value.length);
</script>

<style scoped>
.avatar-stack__item + .avatar-stack__item {
    margin-left: -8px;
}
.avatar-stack__more {
    box-shadow: 0 0 0 2px rgb(var(--v-theme-surface));
}
</style>
