<script setup lang="ts">
import { IconStarFilled } from '@tabler/icons-vue';

defineProps<{
    rating: number;
}>();
</script>

<template>
    <span class="inline-flex items-center gap-0.5" :aria-label="`${rating}/5`">
        <IconStarFilled
            v-for="star in 5"
            :key="star"
            class="size-3"
            :class="
                star <= rating ? 'text-amber-500' : 'text-muted-foreground/30'
            "
        />
    </span>
</template>
