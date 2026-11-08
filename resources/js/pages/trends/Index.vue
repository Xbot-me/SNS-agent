<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { IconFlame, IconRss, IconSearch } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import TrendTopicCard from '@/components/trends/TrendTopicCard.vue';
import { Button } from '@/components/ui/button';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { filled } from '@/lib/helpers';
import { store as analyze } from '@/routes/app/trends/analysis';
import { edit as editSetup } from '@/routes/app/trends/profile';
import type { TrendProfile, TrendTopic } from '@/types/trend';

const props = defineProps<{
    profile: TrendProfile;
    topics: TrendTopic[];
    hasSources: boolean;
    canManage: boolean;
}>();

const isRequesting = ref(false);

const maxScore = computed(() =>
    Math.max(1, ...props.topics.map((topic) => topic.score)),
);

// While an analysis runs, the page asks for fresh topics until it finishes.
const { start: startPolling, stop: stopPolling } = usePoll(
    3000,
    { only: ['profile', 'topics'] },
    { autoStart: false },
);

watch(
    () => props.profile.analyzing,
    (analyzing) => (analyzing ? startPolling() : stopPolling()),
    { immediate: true },
);

const requestAnalysis = (): void => {
    router.post(
        analyze.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isRequesting.value = true;
            },
            onFinish: () => {
                isRequesting.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="$t('trends.title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="$t('trends.title')" :icon="IconFlame" />
        </template>

        <template v-if="canManage" #header-actions>
            <Button variant="outline" as-child>
                <Link :href="editSetup.url()" data-testid="trends-edit-setup">
                    {{ $t('trends.edit_setup') }}
                </Link>
            </Button>
            <Button
                :loading="isRequesting || profile.analyzing"
                data-testid="trends-analyze"
                @click="requestAnalysis"
            >
                {{ $t('trends.analyze_now') }}
            </Button>
        </template>

        <div
            class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-8"
            data-testid="trends-page"
        >
            <div class="flex flex-col gap-1">
                <p class="text-base font-medium text-foreground">
                    {{ profile.niche }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        filled(profile.analyzed_at)
                            ? $t('trends.last_analyzed', {
                                  time: date.diffForHumans(profile.analyzed_at),
                              })
                            : $t('trends.never_analyzed')
                    }}
                </p>
            </div>

            <div
                v-if="topics.length > 0"
                class="flex flex-col gap-4"
                data-testid="trends-list"
            >
                <TrendTopicCard
                    v-for="topic in topics"
                    :key="topic.id"
                    :topic="topic"
                    :max-score="maxScore"
                />
            </div>

            <EmptyState
                v-else-if="profile.analyzing"
                :icon="IconSearch"
                :title="$t('trends.empty.analyzing.title')"
                :description="$t('trends.empty.analyzing.body')"
            />

            <EmptyState
                v-else-if="!hasSources"
                :icon="IconRss"
                :title="$t('trends.empty.no_feeds.title')"
                :description="
                    canManage
                        ? $t('trends.empty.no_feeds.body')
                        : $t('trends.empty.no_feeds.member')
                "
            >
                <template v-if="canManage" #action>
                    <Button as-child>
                        <Link :href="editSetup.url()">
                            {{ $t('trends.empty.no_feeds.action') }}
                        </Link>
                    </Button>
                </template>
            </EmptyState>

            <EmptyState
                v-else
                :icon="IconSearch"
                :title="$t('trends.empty.none.title')"
                :description="$t('trends.empty.none.body')"
            />
        </div>
    </AppLayout>
</template>
