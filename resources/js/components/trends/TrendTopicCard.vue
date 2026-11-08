<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconAlignLeft,
    IconCarouselHorizontal,
    IconChevronDown,
    IconPhoto,
    IconVideo,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import date from '@/date';
import { update as updateTopic } from '@/routes/app/trends/topics';
import { store as makeIdea } from '@/routes/app/trends/topics/idea';
import type { TrendFormat, TrendTopic } from '@/types/trend';

const props = defineProps<{
    topic: TrendTopic;
    maxScore: number;
}>();

const formatIcons: Record<TrendFormat, Component> = {
    text: IconAlignLeft,
    image: IconPhoto,
    carousel: IconCarouselHorizontal,
    video: IconVideo,
};

const isMakingPost = ref(false);
const isSourcesOpen = ref(false);

const toggleSources = (): void => {
    isSourcesOpen.value = !isSourcesOpen.value;
};

const makePost = (): void => {
    router.post(
        makeIdea.url(props.topic.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isMakingPost.value = true;
            },
            onFinish: () => {
                isMakingPost.value = false;
            },
            onError: () => {
                toast.error(trans('trends.topic.make_failed'));
            },
        },
    );
};

const dismiss = (): void => {
    router.patch(
        updateTopic.url(props.topic.id),
        { status: 'dismissed' },
        { preserveScroll: true },
    );
};
</script>

<template>
    <article
        class="flex flex-col gap-4 rounded-xl border border-border bg-card p-4"
        :data-testid="`trend-topic-${topic.id}`"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h2
                    class="truncate font-heading text-base font-medium text-foreground"
                >
                    {{ topic.label }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        $tChoice('trends.topic.covered_by', topic.outlet_count)
                    }}
                    ·
                    {{ date.diffForHumans(topic.last_seen_at) }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-2">
                <Badge
                    v-if="topic.own_posts > 0"
                    :variant="
                        (topic.own_ratio ?? 0) >= 120 ? 'success' : 'outline'
                    "
                    :data-testid="`trend-topic-own-${topic.id}`"
                >
                    {{
                        (topic.own_ratio ?? 0) >= 120
                            ? $t('trends.topic.did_well')
                            : $t('trends.topic.posted_before')
                    }}
                </Badge>
                <Badge v-if="topic.status === 'used'" variant="success">
                    {{ $t('trends.topic.used') }}
                </Badge>
            </div>
        </div>

        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            aria-hidden="true"
        >
            <div
                class="h-full rounded-full bg-primary-strong"
                :style="{
                    width: `${Math.max(6, Math.round((topic.score / maxScore) * 100))}%`,
                }"
            />
        </div>

        <div v-if="topic.formats.length > 0" class="flex flex-wrap gap-2">
            <Badge
                v-for="format in topic.formats"
                :key="format"
                variant="outline"
                class="h-6 gap-1.5 px-2"
            >
                <component :is="formatIcons[format]" />
                {{ $t(`trends.formats.${format}`) }}
            </Badge>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <Button
                variant="ghost"
                size="sm"
                class="-ms-2"
                :aria-expanded="isSourcesOpen"
                :data-testid="`trend-topic-sources-${topic.id}`"
                @click="toggleSources"
            >
                {{
                    isSourcesOpen
                        ? $t('trends.topic.hide_sources')
                        : $t('trends.topic.show_sources')
                }}
                <IconChevronDown
                    :class="[
                        'transition-transform',
                        isSourcesOpen ? 'rotate-180' : '',
                    ]"
                />
            </Button>

            <div class="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    :data-testid="`trend-topic-dismiss-${topic.id}`"
                    @click="dismiss"
                >
                    {{ $t('trends.topic.dismiss') }}
                </Button>
                <Button
                    size="sm"
                    :loading="isMakingPost"
                    :data-testid="`trend-topic-make-post-${topic.id}`"
                    @click="makePost"
                >
                    {{ $t('trends.topic.make_post') }}
                </Button>
            </div>
        </div>

        <ul
            v-if="isSourcesOpen"
            class="flex flex-col gap-2 border-t border-border pt-3"
            :data-testid="`trend-topic-evidence-${topic.id}`"
        >
            <li
                v-for="item in topic.evidence"
                :key="item.url"
                class="flex flex-col text-sm"
            >
                <a
                    :href="item.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-foreground hover:underline"
                >
                    {{ item.title }}
                </a>
                <span class="text-xs text-muted-foreground">
                    {{ item.source }} ·
                    {{ date.diffForHumans(item.published_at) }}
                </span>
            </li>
        </ul>
    </article>
</template>
