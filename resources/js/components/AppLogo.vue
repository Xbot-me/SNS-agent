<script setup lang="ts">
withDefaults(
    defineProps<{
        /** `full` is the mark and the "X-board" wordmark, `mark` the round mark alone. */
        variant?: 'full' | 'mark';
    }>(),
    { variant: 'full' },
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-[0.32em] font-logo leading-none font-bold tracking-[-0.01em] text-foreground"
        role="img"
        aria-label="X-board"
        data-testid="app-logo"
    >
        <svg
            viewBox="0 0 410 410"
            class="size-[1.15em] shrink-0 [clip-path:circle(50%)]"
            aria-hidden="true"
        >
            <rect width="410" height="410" fill="#6D28D9" />
            <path
                d="M110 100H175L205 160L235 100H300L240 205L300 310H235L205 250L175 310H110L170 205Z"
                fill="#FFFFFF"
            />
        </svg>
        <span v-if="variant === 'full'" aria-hidden="true"
            >X-board</span
        >
    </span>
</template>
