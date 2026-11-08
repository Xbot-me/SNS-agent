<script setup lang="ts">
import { Head, InfiniteScroll, router, usePoll } from '@inertiajs/vue3';
import { IconInbox, IconRefresh } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import InboxChannelNotices from '@/components/inbox/InboxChannelNotices.vue';
import InboxConversationRow from '@/components/inbox/InboxConversationRow.vue';
import InboxThread from '@/components/inbox/InboxThread.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Button } from '@/components/ui/button';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import debounce from '@/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import { filled } from '@/lib/helpers';
import { cn } from '@/lib/utils';
import { index as inboxIndex, show as inboxShow } from '@/routes/app/inbox';
import { store as syncInbox } from '@/routes/app/inbox/sync';
import type {
    InboxChannel,
    InboxConversationDetail,
    InboxConversationSummary,
    InboxFilters,
    InboxKind,
} from '@/types/inbox';

interface ScrollConversations {
    data: InboxConversationSummary[];
    meta: { hasNextPage: boolean };
}

const props = defineProps<{
    conversations: ScrollConversations;
    conversation: InboxConversationDetail | null;
    channels: InboxChannel[];
    unreadCount: number;
    hasData: boolean;
    filters: InboxFilters;
}>();

const KINDS: InboxKind[] = ['message', 'comment', 'mention', 'review'];
const POLL_INTERVAL_MS = 30_000;
const RELOAD_AFTER_SYNC_MS = 8_000;

const kind = ref<InboxKind | null>(props.filters.kind);
const channel = ref<string>(props.filters.channel ?? '');
const unreadOnly = ref<boolean>(props.filters.unread);
const searchQuery = ref<string>(props.filters.search ?? '');
const isSyncing = ref(false);

/** Rows opened since the list loaded: read now, though the list still says unread. */
const openedIds = ref(new Set<string>());

const query = (): Record<string, string | undefined> => ({
    kind: kind.value ?? undefined,
    channel: channel.value || undefined,
    unread: unreadOnly.value ? '1' : undefined,
    search: searchQuery.value || undefined,
});

const applyFilters = (): void => {
    router.get(
        inboxIndex.url({ query: query() }),
        {},
        { preserveState: true, preserveScroll: true, reset: ['conversations'] },
    );
};

const search = debounce(applyFilters, 300);

watch(searchQuery, () => search());
watch(channel, applyFilters);

const selectKind = (value: InboxKind | null): void => {
    kind.value = value;
    applyFilters();
};

const toggleUnread = (): void => {
    unreadOnly.value = !unreadOnly.value;
    applyFilters();
};

const openConversation = (id: string): void => {
    openedIds.value.add(id);

    router.get(
        inboxShow.url(id, { query: query() }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: ['conversation', 'unreadCount'],
        },
    );
};

const closeConversation = (): void => {
    router.get(
        inboxIndex.url({ query: query() }),
        {},
        { preserveState: true, preserveScroll: true, only: ['conversation'] },
    );
};

const isUnread = (item: InboxConversationSummary): boolean =>
    item.is_unread &&
    !openedIds.value.has(item.id) &&
    item.id !== props.conversation?.id;

const reloadInbox = (): void => {
    openedIds.value = new Set();
    router.reload({
        only: ['conversations', 'conversation', 'unreadCount', 'channels'],
        reset: ['conversations'],
    });
};

const checkNow = (): void => {
    router.post(
        syncInbox.url(),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['channels'],
            onStart: () => {
                isSyncing.value = true;
            },
            onFinish: () => {
                setTimeout(() => {
                    isSyncing.value = false;
                    reloadInbox();
                }, RELOAD_AFTER_SYNC_MS);
            },
        },
    );
};

usePoll(
    POLL_INTERVAL_MS,
    () => ({
        only: ['conversations', 'conversation', 'unreadCount', 'channels'],
        reset: ['conversations'],
        onSuccess: () => {
            openedIds.value = new Set();
        },
    }),
    { mode: 'rest' },
);

const hasFilters = computed(
    () =>
        kind.value !== null ||
        filled(channel.value) ||
        unreadOnly.value ||
        filled(searchQuery.value),
);
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('inbox.title')" />

        <div class="flex h-full min-h-0 flex-col gap-4 px-4 pt-6 pb-4 md:px-8">
            <header class="flex items-center justify-between gap-4">
                <HeaderTitle
                    :title="$t('inbox.title')"
                    :icon="IconInbox"
                    :total="unreadCount || null"
                />
                <Button
                    variant="outline"
                    :loading="isSyncing"
                    data-testid="inbox-check-now"
                    @click="checkNow"
                >
                    <IconRefresh class="size-4" />
                    {{ $t('inbox.check_now') }}
                </Button>
            </header>

            <InboxChannelNotices :channels="channels" />

            <EmptyState
                v-if="!hasData"
                :icon="IconInbox"
                :title="$t('inbox.empty.title')"
                :description="$t('inbox.empty.description')"
                data-testid="inbox-empty"
            />

            <div
                v-else
                class="grid min-h-0 flex-1 overflow-hidden rounded-xl border border-border bg-card md:grid-cols-[minmax(18rem,24rem)_1fr]"
            >
                <aside
                    :class="
                        cn(
                            'flex min-h-0 flex-col border-border md:border-e',
                            conversation && 'hidden md:flex',
                        )
                    "
                >
                    <div class="flex flex-col gap-2 border-b border-border p-3">
                        <SettingsSearch
                            v-model="searchQuery"
                            :placeholder="$t('inbox.search')"
                        />
                        <div
                            class="flex flex-wrap gap-1.5"
                            data-testid="inbox-kind-filters"
                        >
                            <Button
                                size="sm"
                                :variant="kind === null ? 'secondary' : 'ghost'"
                                data-testid="inbox-kind-all"
                                @click="selectKind(null)"
                            >
                                {{ $t('inbox.filters.all') }}
                            </Button>
                            <Button
                                v-for="value in KINDS"
                                :key="value"
                                size="sm"
                                :variant="
                                    kind === value ? 'secondary' : 'ghost'
                                "
                                :data-testid="`inbox-kind-${value}`"
                                @click="selectKind(value)"
                            >
                                {{ $t('inbox.kinds.' + value) }}
                            </Button>
                        </div>
                        <div class="flex items-center gap-2">
                            <NativeSelect
                                v-model="channel"
                                class="w-full"
                                :aria-label="$t('inbox.all_channels')"
                                data-testid="inbox-channel-filter"
                            >
                                <NativeSelectOption value="">
                                    {{ $t('inbox.all_channels') }}
                                </NativeSelectOption>
                                <NativeSelectOption
                                    v-for="item in channels"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.name }}
                                </NativeSelectOption>
                            </NativeSelect>
                            <Button
                                size="sm"
                                :variant="unreadOnly ? 'secondary' : 'outline'"
                                :aria-pressed="unreadOnly"
                                data-testid="inbox-unread-filter"
                                @click="toggleUnread"
                            >
                                {{ $t('inbox.filters.unread') }}
                            </Button>
                        </div>
                    </div>

                    <EmptyState
                        v-if="conversations.data.length === 0"
                        :icon="IconInbox"
                        :title="$t('inbox.no_results.title')"
                        :description="
                            hasFilters
                                ? $t('inbox.no_results.description')
                                : undefined
                        "
                        data-testid="inbox-no-results"
                    />

                    <div v-else class="min-h-0 flex-1 overflow-y-auto p-1.5">
                        <InfiniteScroll
                            data="conversations"
                            items-element="#inbox-conversations"
                            preserve-url
                        >
                            <ul id="inbox-conversations" class="flex flex-col">
                                <InboxConversationRow
                                    v-for="item in conversations.data"
                                    :key="item.id"
                                    :conversation="item"
                                    :active="item.id === conversation?.id"
                                    :unread="isUnread(item)"
                                    @select="openConversation"
                                />
                            </ul>
                        </InfiniteScroll>
                    </div>
                </aside>

                <div :class="cn('min-h-0', !conversation && 'hidden md:block')">
                    <InboxThread
                        v-if="conversation"
                        :key="conversation.id"
                        :conversation="conversation"
                        @back="closeConversation"
                    />
                    <EmptyState
                        v-else
                        :icon="IconInbox"
                        :title="$t('inbox.no_selection')"
                        data-testid="inbox-no-selection"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
