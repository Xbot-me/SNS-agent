<script setup lang="ts">
import { IconCornerDownRight } from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InboxRating from '@/components/inbox/InboxRating.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import date from '@/date';
import { filled } from '@/lib/helpers';
import { cn } from '@/lib/utils';
import type { InboxConversationSummary } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxConversationSummary;
    active: boolean;
    unread: boolean;
}>();

const emit = defineEmits<{
    select: [id: string];
}>();

const select = (): void => {
    emit('select', props.conversation.id);
};

const initial = (name: string | null): string =>
    (name ?? '?').trim().charAt(0).toUpperCase() || '?';
</script>

<template>
    <li>
        <button
            type="button"
            :class="
                cn(
                    'flex w-full items-start gap-3 rounded-lg px-3 py-3 text-start transition-colors hover:bg-accent',
                    active && 'bg-accent',
                )
            "
            :data-testid="`inbox-row-${conversation.id}`"
            :data-unread="unread ? 'true' : 'false'"
            @click="select"
        >
            <div class="relative shrink-0">
                <Avatar class="size-10">
                    <AvatarImage
                        v-if="conversation.participant_avatar_url"
                        :src="conversation.participant_avatar_url"
                    />
                    <AvatarFallback>{{
                        initial(conversation.participant_name)
                    }}</AvatarFallback>
                </Avatar>
                <ChannelAvatar
                    class="absolute -end-1.5 -bottom-1.5"
                    :platform="conversation.platform"
                    :name="conversation.channel.name ?? ''"
                    :src="conversation.channel.avatar_url"
                    :size="20"
                    :reserve-space="false"
                />
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <p
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-sm text-foreground',
                                unread ? 'font-semibold' : 'font-medium',
                            )
                        "
                    >
                        {{
                            conversation.participant_name ??
                            conversation.participant_username ??
                            $t('inbox.kinds.' + conversation.kind)
                        }}
                    </p>
                    <span
                        v-if="conversation.last_message_at"
                        class="shrink-0 text-xs text-muted-foreground"
                    >
                        {{ date.diffForHumans(conversation.last_message_at) }}
                    </span>
                </div>
                <p
                    v-if="filled(conversation.subject)"
                    class="flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                    :title="$t('inbox.on_post')"
                >
                    <IconCornerDownRight class="size-3 shrink-0" />
                    <span class="truncate">{{ conversation.subject }}</span>
                </p>
                <InboxRating
                    v-if="conversation.rating"
                    :rating="conversation.rating"
                    class="mt-0.5"
                />
                <div class="mt-0.5 flex items-center gap-2">
                    <p
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-sm',
                                unread
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            )
                        "
                    >
                        {{ conversation.last_message_excerpt }}
                    </p>
                    <span
                        v-if="unread"
                        class="size-2 shrink-0 rounded-full bg-primary"
                        data-testid="inbox-unread-dot"
                    />
                </div>
            </div>
        </button>
    </li>
</template>
