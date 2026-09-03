<script setup>
defineProps({
  stats: {
    type: Array,
    default: () => [],
  },
});
</script>

<template>
  <v-row class="mt-2">
    <v-col v-for="stat in stats" :key="stat.label" cols="12" sm="6" md="3">
      <v-card variant="flat" rounded="lg" class="pa-4 stat-card border">
        <div class="d-flex align-center justify-space-between">
          <div class="text-h5 font-weight-bold" :class="`text-${stat.color}`">{{ stat.value }}</div>
          <v-avatar :color="stat.color" variant="tonal" size="36">
            <v-icon :icon="stat.icon" size="20" />
          </v-avatar>
        </div>
        <div class="text-caption mt-2" style="opacity: 0.7;">{{ stat.label }}</div>
      </v-card>
    </v-col>
  </v-row>
</template>

<style scoped>
.stat-card {
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}
</style>
