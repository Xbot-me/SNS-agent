<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { filled } from '@/lib/helpers';
import { channels as workspaceChannels } from '@/routes/app/workspace';
import type { InboxChannel } from '@/types/inbox';

const props = defineProps<{
    channels: InboxChannel[];
}>();

const RECONNECT_ERRORS = ['authentication', 'permission'];

/**
 * A channel the inbox cannot fully read: its login left out an inbox
 * permission or the network refused the token, or its last read failed.
 */
const notices = computed(() =>
    props.channels
        .filter((channel) => channel.is_connected)
        .flatMap((channel) => {
            if (
                filled(channel.missing) ||
                RECONNECT_ERRORS.includes(channel.error ?? '')
            ) {
                return [
                    { channel, key: 'inbox.notices.missing', reconnect: true },
                ];
            }

            if (filled(channel.error) && channel.error !== 'unsupported') {
                return [
                    { channel, key: 'inbox.notices.failed', reconnect: false },
                ];
            }

            return [];
        }),
);
</script>

<template>
    <ul
        v-if="filled(notices)"
        class="flex flex-col gap-2"
        data-testid="inbox-channel-notices"
    >
        <li
            v-for="notice in notices"
            :key="notice.channel.id"
            class="flex flex-col gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm sm:flex-row sm:items-center"
            :data-testid="`inbox-notice-${notice.channel.id}`"
        >
            <IconAlertTriangle
                class="hidden size-4 shrink-0 text-amber-500 sm:block"
            />
            <p class="min-w-0 flex-1 text-muted-foreground">
                {{ $t(notice.key, { channel: notice.channel.name }) }}
            </p>
            <Button
                v-if="notice.reconnect"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="workspaceChannels.url()">
                    {{ $t('inbox.notices.open_channels') }}
                </Link>
            </Button>
        </li>
    </ul>
</template>
