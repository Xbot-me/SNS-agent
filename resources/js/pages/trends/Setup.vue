<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    IconAlignLeft,
    IconCarouselHorizontal,
    IconCheck,
    IconFlame,
    IconPhoto,
    IconVideo,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    TagsInput,
    TagsInputInput,
    TagsInputItem,
    TagsInputItemDelete,
    TagsInputItemText,
} from '@/components/ui/tags-input';
import AppLayout from '@/layouts/AppLayout.vue';
import { filled } from '@/lib/helpers';
import {
    index as feedsIndex,
    store as storeFeed,
} from '@/routes/app/create/feeds';
import { index as trendsIndex } from '@/routes/app/trends';
import { update as saveProfile } from '@/routes/app/trends/profile';
import type {
    TrendDirectoryCategory,
    TrendDirectoryEntry,
    TrendFormat,
    TrendGoal,
    TrendNiche,
    TrendProfile,
    TrendSetupFeed,
    TrendSetupLimits,
} from '@/types/trend';

const props = defineProps<{
    profile: TrendProfile | null;
    niches: TrendNiche[];
    goals: TrendGoal[];
    formats: TrendFormat[];
    feeds: TrendSetupFeed[];
    directory: TrendDirectoryCategory[];
    limits: TrendSetupLimits;
}>();

const steps = ['niche', 'goal', 'formats', 'sources'] as const;
const postsPerWeekChoices = [1, 2, 3, 5, 7, 10, 14];
// Enough to start with; the Feeds page has the whole directory.
const SUGGESTED_FEEDS = 8;
const formatIcons: Record<TrendFormat, Component> = {
    text: IconAlignLeft,
    image: IconPhoto,
    carousel: IconCarouselHorizontal,
    video: IconVideo,
};

const form = useForm({
    niche: props.profile?.niche ?? '',
    audience: props.profile?.audience ?? '',
    goal: props.profile?.goal ?? ('grow' as TrendGoal),
    keywords: [...(props.profile?.keywords ?? [])],
    excluded_keywords: [...(props.profile?.excluded_keywords ?? [])],
    formats: [
        ...(props.profile?.formats ?? (['text', 'image'] as TrendFormat[])),
    ],
    posts_per_week: props.profile?.posts_per_week ?? 3,
    rss_feed_ids:
        props.profile?.rss_feed_ids ??
        props.feeds.slice(0, props.limits.feeds).map((feed) => feed.id),
});

const stepIndex = ref(0);
const selectedNiche = ref<string | null>(null);
const addingUrl = ref<string | null>(null);
const knownFeedIds = new Set(props.feeds.map((feed) => feed.id));

const step = computed(() => steps[stepIndex.value]);
const isLastStep = computed(() => stepIndex.value === steps.length - 1);
const isEditing = computed(() => filled(props.profile));

const canContinue = computed(() => {
    if (step.value === 'niche') {
        return filled(form.niche) && form.keywords.length > 0;
    }

    if (step.value === 'formats') {
        return form.formats.length > 0;
    }

    return true;
});

const suggestedEntries = computed<TrendDirectoryEntry[]>(() => {
    const niche = props.niches.find((item) => item.key === selectedNiche.value);

    if (!niche) {
        return [];
    }

    return (
        props.directory
            .find((category) => category.key === niche.feed_category)
            ?.entries.filter((entry) => !entry.subscribed)
            .slice(0, SUGGESTED_FEEDS) ?? []
    );
});

const feedLimitReached = computed(
    () => form.rss_feed_ids.length >= props.limits.feeds,
);

// A feed added from the suggestions starts selected.
watch(
    () => props.feeds,
    (feeds) => {
        for (const feed of feeds) {
            if (knownFeedIds.has(feed.id)) {
                continue;
            }

            knownFeedIds.add(feed.id);

            if (!feedLimitReached.value) {
                form.rss_feed_ids = [...form.rss_feed_ids, feed.id];
            }
        }
    },
);

const pickNiche = (niche: TrendNiche): void => {
    selectedNiche.value = niche.key;
    form.niche = trans(`trends.niches.${niche.key}`);
    form.keywords = [...niche.keywords];
};

const pickGoal = (goal: TrendGoal): void => {
    form.goal = goal;
};

const toggleFormat = (format: TrendFormat): void => {
    form.formats = form.formats.includes(format)
        ? form.formats.filter((item) => item !== format)
        : [...form.formats, format];
};

const pickPostsPerWeek = (count: number): void => {
    form.posts_per_week = count;
};

const toggleFeed = (feedId: string, checked: boolean): void => {
    form.rss_feed_ids = checked
        ? [...form.rss_feed_ids, feedId]
        : form.rss_feed_ids.filter((id) => id !== feedId);
};

const addFeed = (entry: TrendDirectoryEntry): void => {
    router.post(
        storeFeed.url(),
        { url: entry.url, source: 'explore' },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['feeds', 'directory'],
            onStart: () => {
                addingUrl.value = entry.url;
            },
            onFinish: () => {
                addingUrl.value = null;
            },
            onError: () => {
                toast.error(trans('trends.setup.sources.add_failed'));
            },
        },
    );
};

const goBack = (): void => {
    stepIndex.value -= 1;
};

const goNext = (): void => {
    if (isLastStep.value) {
        form.put(saveProfile.url(), {
            onError: () => {
                toast.error(trans('trends.setup.save_failed'));
            },
        });

        return;
    }

    stepIndex.value += 1;
};
</script>

<template>
    <Head :title="$t('trends.setup.title')" />

    <AppLayout full-width>
        <template #header>
            <HeaderTitle :title="$t('trends.setup.title')" :icon="IconFlame" />
        </template>

        <div
            class="mx-auto flex w-full max-w-2xl flex-col gap-8 p-4 sm:p-8"
            data-testid="trends-setup"
        >
            <div class="flex flex-col gap-3">
                <p class="text-sm text-muted-foreground">
                    {{
                        $t('trends.setup.step', {
                            current: stepIndex + 1,
                            total: steps.length,
                        })
                    }}
                    · {{ $t(`trends.setup.steps.${step}`) }}
                </p>
                <div class="flex gap-1.5" aria-hidden="true">
                    <span
                        v-for="(name, index) in steps"
                        :key="name"
                        :class="[
                            'h-1 flex-1 rounded-full',
                            index <= stepIndex
                                ? 'bg-primary-strong'
                                : 'bg-muted',
                        ]"
                    />
                </div>
            </div>

            <section
                v-if="step === 'niche'"
                class="flex flex-col gap-6"
                data-testid="trends-step-niche"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="font-heading text-lg font-medium">
                        {{ $t('trends.setup.niche.heading') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.niche.description') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="niche in niches"
                        :key="niche.key"
                        type="button"
                        :aria-pressed="selectedNiche === niche.key"
                        :data-testid="`trends-niche-${niche.key}`"
                        :class="[
                            'cursor-pointer rounded-full border px-3 py-1.5 text-sm transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            selectedNiche === niche.key
                                ? 'border-primary-strong bg-primary-subtle'
                                : 'border-border bg-card hover:border-border-strong',
                        ]"
                        @click="pickNiche(niche)"
                    >
                        {{ $t(`trends.niches.${niche.key}`) }}
                    </button>
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="trends-niche">
                        {{ $t('trends.setup.niche.label') }}
                    </Label>
                    <Input
                        id="trends-niche"
                        v-model="form.niche"
                        data-testid="trends-niche"
                        :placeholder="$t('trends.setup.niche.placeholder')"
                    />
                    <InputError :message="form.errors.niche" />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="trends-keywords">
                        {{ $t('trends.setup.niche.keywords_label') }}
                    </Label>
                    <TagsInput
                        v-model="form.keywords"
                        :max="limits.keywords"
                        data-testid="trends-keywords"
                    >
                        <TagsInputItem
                            v-for="keyword in form.keywords"
                            :key="keyword"
                            :value="keyword"
                        >
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput
                            id="trends-keywords"
                            :placeholder="
                                $t('trends.setup.niche.keywords_placeholder')
                            "
                        />
                    </TagsInput>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.niche.keywords_description') }}
                    </p>
                    <InputError :message="form.errors.keywords" />
                </div>

                <div class="flex flex-col gap-2">
                    <Label for="trends-excluded">
                        {{ $t('trends.setup.niche.excluded_label') }}
                    </Label>
                    <TagsInput
                        v-model="form.excluded_keywords"
                        :max="limits.keywords"
                        data-testid="trends-excluded"
                    >
                        <TagsInputItem
                            v-for="keyword in form.excluded_keywords"
                            :key="keyword"
                            :value="keyword"
                        >
                            <TagsInputItemText />
                            <TagsInputItemDelete />
                        </TagsInputItem>
                        <TagsInputInput
                            id="trends-excluded"
                            :placeholder="
                                $t('trends.setup.niche.keywords_placeholder')
                            "
                        />
                    </TagsInput>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.niche.excluded_description') }}
                    </p>
                </div>
            </section>

            <section
                v-else-if="step === 'goal'"
                class="flex flex-col gap-6"
                data-testid="trends-step-goal"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="font-heading text-lg font-medium">
                        {{ $t('trends.setup.goal.heading') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.goal.description') }}
                    </p>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="goal in goals"
                        :key="goal"
                        type="button"
                        :aria-pressed="form.goal === goal"
                        :data-testid="`trends-goal-${goal}`"
                        :class="[
                            'flex cursor-pointer flex-col gap-1 rounded-xl border p-3 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            form.goal === goal
                                ? 'border-primary-strong bg-primary-subtle'
                                : 'border-border bg-card hover:border-border-strong',
                        ]"
                        @click="pickGoal(goal)"
                    >
                        <span class="text-sm font-medium">
                            {{ $t(`trends.goals.${goal}.label`) }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ $t(`trends.goals.${goal}.description`) }}
                        </span>
                    </button>
                </div>
                <InputError :message="form.errors.goal" />

                <div class="flex flex-col gap-2">
                    <Label for="trends-audience">
                        {{ $t('trends.setup.goal.audience_label') }}
                    </Label>
                    <Input
                        id="trends-audience"
                        v-model="form.audience"
                        data-testid="trends-audience"
                        :placeholder="
                            $t('trends.setup.goal.audience_placeholder')
                        "
                    />
                    <InputError :message="form.errors.audience" />
                </div>
            </section>

            <section
                v-else-if="step === 'formats'"
                class="flex flex-col gap-6"
                data-testid="trends-step-formats"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="font-heading text-lg font-medium">
                        {{ $t('trends.setup.formats.heading') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.formats.description') }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button
                        v-for="format in formats"
                        :key="format"
                        type="button"
                        :aria-pressed="form.formats.includes(format)"
                        :data-testid="`trends-format-${format}`"
                        :class="[
                            'flex cursor-pointer flex-col items-center gap-2 rounded-xl border p-4 text-sm transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            form.formats.includes(format)
                                ? 'border-primary-strong bg-primary-subtle'
                                : 'border-border bg-card hover:border-border-strong',
                        ]"
                        @click="toggleFormat(format)"
                    >
                        <component :is="formatIcons[format]" class="size-6" />
                        {{ $t(`trends.formats.${format}`) }}
                    </button>
                </div>
                <InputError :message="form.errors.formats" />

                <div class="flex flex-col gap-2">
                    <Label>{{
                        $t('trends.setup.formats.cadence_label')
                    }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="count in postsPerWeekChoices"
                            :key="count"
                            type="button"
                            :aria-pressed="form.posts_per_week === count"
                            :data-testid="`trends-cadence-${count}`"
                            :class="[
                                'min-w-10 cursor-pointer rounded-full border px-3 py-1.5 text-sm transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                                form.posts_per_week === count
                                    ? 'border-primary-strong bg-primary-subtle'
                                    : 'border-border bg-card hover:border-border-strong',
                            ]"
                            @click="pickPostsPerWeek(count)"
                        >
                            {{ count }}
                        </button>
                    </div>
                    <InputError :message="form.errors.posts_per_week" />
                </div>
            </section>

            <section
                v-else
                class="flex flex-col gap-6"
                data-testid="trends-step-sources"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="font-heading text-lg font-medium">
                        {{ $t('trends.setup.sources.heading') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('trends.setup.sources.description') }}
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-medium">
                        {{ $t('trends.setup.sources.yours') }}
                    </h3>
                    <p
                        v-if="feeds.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('trends.setup.sources.none') }}
                    </p>
                    <div
                        v-for="feed in feeds"
                        :key="feed.id"
                        class="flex items-center gap-3 rounded-lg border border-border bg-card p-3"
                    >
                        <Checkbox
                            :id="`trends-feed-${feed.id}`"
                            :model-value="form.rss_feed_ids.includes(feed.id)"
                            :disabled="
                                feedLimitReached &&
                                !form.rss_feed_ids.includes(feed.id)
                            "
                            :data-testid="`trends-feed-${feed.id}`"
                            @update:model-value="
                                toggleFeed(feed.id, Boolean($event))
                            "
                        />
                        <Label
                            :for="`trends-feed-${feed.id}`"
                            class="min-w-0 flex-1 truncate"
                        >
                            {{ feed.title }}
                        </Label>
                    </div>
                    <InputError :message="form.errors.rss_feed_ids" />
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('trends.setup.sources.limit', {
                                count: limits.feeds,
                            })
                        }}
                    </p>
                </div>

                <div
                    v-if="suggestedEntries.length > 0"
                    class="flex flex-col gap-2"
                >
                    <h3 class="text-sm font-medium">
                        {{
                            $t('trends.setup.sources.suggested', {
                                niche: form.niche,
                            })
                        }}
                    </h3>
                    <div
                        v-for="entry in suggestedEntries"
                        :key="entry.url"
                        class="flex items-center justify-between gap-3 rounded-lg border border-border bg-card p-3"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ entry.name }}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            :loading="addingUrl === entry.url"
                            :disabled="addingUrl !== null || feedLimitReached"
                            :data-testid="`trends-add-feed-${entry.name}`"
                            @click="addFeed(entry)"
                        >
                            {{ $t('trends.setup.sources.add') }}
                        </Button>
                    </div>
                </div>

                <Link
                    :href="feedsIndex.url()"
                    class="text-sm text-muted-foreground underline underline-offset-4 hover:text-foreground"
                >
                    {{ $t('trends.setup.sources.more') }}
                </Link>
            </section>

            <div class="flex items-center justify-between gap-2">
                <Button v-if="stepIndex > 0" variant="outline" @click="goBack">
                    {{ $t('trends.setup.back') }}
                </Button>
                <Button v-else-if="isEditing" variant="outline" as-child>
                    <Link :href="trendsIndex.url()">
                        {{ $t('common.cancel') }}
                    </Link>
                </Button>
                <span v-else />

                <Button
                    :loading="form.processing"
                    :disabled="!canContinue"
                    data-testid="trends-continue"
                    @click="goNext"
                >
                    <template v-if="isLastStep">
                        <IconCheck class="size-4" />
                        {{
                            isEditing
                                ? $t('trends.setup.save')
                                : $t('trends.setup.finish')
                        }}
                    </template>
                    <template v-else>{{ $t('trends.setup.next') }}</template>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
