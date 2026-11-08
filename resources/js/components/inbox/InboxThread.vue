<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconExternalLink, IconFile } from '@tabler/icons-vue';
import { nextTick, onMounted, ref, watch } from 'vue';

import InboxRating from '@/components/inbox/InboxRating.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import date from '@/date';
import { blank, filled } from '@/lib/helpers';
import { cn } from '@/lib/utils';
import { store as storeReply } from '@/routes/app/inbox/replies';
import type { InboxConversationDetail } from '@/types/inbox';

const props = defineProps<{
    conversation: InboxConversationDetail;
}>();

const emit = defineEmits<{
    back: [];
}>();

const form = useForm({ body: '' });
const messagesEnd = ref<HTMLElement | null>(null);

const scrollToLatest = (): void => {
    nextTick(() => messagesEnd.value?.scrollIntoView({ block: 'end' }));
};

onMounted(scrollToLatest);
watch(() => props.conversation.messages.length, scrollToLatest);
watch(
    () => props.conversation.id,
    () => {
        form.reset();
        form.clearErrors();
        scrollToLatest();
    },
);

const goBack = (): void => {
    emit('back');
};

const sendReply = (): void => {
    if (blank(form.body)) {
        return;
    }

    form.post(storeReply.url(props.conversation.id), {
        preserveScroll: true,
        preserveState: true,
        only: ['conversation', 'unreadCount', 'errors'],
        onSuccess: () => form.reset('body'),
    });
};

const sendOnShortcut = (event: KeyboardEvent): void => {
    if (event.metaKey || event.ctrlKey) {
        event.preventDefault();
        sendReply();
    }
};

const initial = (name: string | null): string =>
    (name ?? '?').trim().charAt(0).toUpperCase() || '?';
</script>

<template>
    <section class="flex h-full min-h-0 flex-col" data-testid="inbox-thread">
        <header
            class="flex items-center gap-3 border-b border-border px-4 py-3"
        >
            <Button
                variant="ghost"
                size="icon"
                class="md:hidden"
                :aria-label="$t('inbox.back')"
                data-testid="inbox-thread-back"
                @click="goBack"
            >
                <IconArrowLeft class="size-4" />
            </Button>
            <Avatar class="size-9">
                <AvatarImage
                    v-if="conversation.participant_avatar_url"
                    :src="conversation.participant_avatar_url"
                />
                <AvatarFallback>{{
                    initial(conversation.participant_name)
                }}</AvatarFallback>
            </Avatar>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-foreground">
                    {{
                        conversation.participant_name ??
                        conversation.participant_username ??
                        $t('inbox.kinds.' + conversation.kind)
                    }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ $t('inbox.kinds.' + conversation.kind) }} ·
                    {{ conversation.channel.name }}
                </p>
            </div>
            <Button
                v-if="conversation.permalink"
                variant="outline"
                size="sm"
                as-child
            >
                <a
                    :href="conversation.permalink"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="inbox-thread-permalink"
                >
                    <IconExternalLink class="size-4" />
                    <span class="hidden sm:inline">{{
                        $t('inbox.open_on', {
                            network: conversation.network_name,
                        })
                    }}</span>
                </a>
            </Button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4">
            <div
                v-if="filled(conversation.subject)"
                class="mb-4 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
            >
                <span class="font-medium text-foreground">{{
                    $t('inbox.on_post')
                }}</span>
                <p class="mt-0.5 line-clamp-3">{{ conversation.subject }}</p>
            </div>
            <InboxRating
                v-if="conversation.rating"
                :rating="conversation.rating"
                class="mb-3"
            />
            <ol class="flex flex-col gap-3">
                <li
                    v-for="message in conversation.messages"
                    :key="message.id"
                    :class="
                        cn(
                            'flex max-w-[85%] flex-col gap-1',
                            message.is_own
                                ? 'ms-auto items-end'
                                : 'items-start',
                        )
                    "
                    :data-testid="`inbox-message-${message.id}`"
                >
                    <span class="px-1 text-xs text-muted-foreground">
                        {{
                            message.is_own
                                ? $t('inbox.you')
                                : (message.author_name ??
                                  message.author_username)
                        }}
                        · {{ date.formatDateTime(message.sent_at) }}
                    </span>
                    <div
                        v-if="filled(message.body)"
                        :class="
                            cn(
                                'rounded-2xl px-3 py-2 text-sm break-words whitespace-pre-line',
                                message.is_own
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-foreground',
                            )
                        "
                    >
                        {{ message.body }}
                    </div>
                    <div
                        v-if="filled(message.attachments)"
                        class="flex flex-wrap gap-2"
                    >
                        <a
                            v-for="attachment in message.attachments"
                            :key="attachment.url"
                            :href="attachment.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="block overflow-hidden rounded-lg border border-border"
                        >
                            <img
                                v-if="attachment.type === 'image'"
                                :src="attachment.url"
                                alt=""
                                class="max-h-48 max-w-60 object-cover"
                                loading="lazy"
                            />
                            <span
                                v-else
                                class="flex items-center gap-2 px-3 py-2 text-sm"
                            >
                                <IconFile class="size-4" />
                                {{ attachment.type }}
                            </span>
                        </a>
                    </div>
                </li>
            </ol>
            <div ref="messagesEnd" />
        </div>

        <form class="border-t border-border p-3" @submit.prevent="sendReply">
            <p
                v-if="conversation.kind === 'review'"
                class="mb-2 text-xs text-muted-foreground"
            >
                {{ $t('inbox.reply.review_note') }}
            </p>
            <div class="flex items-end gap-2">
                <Textarea
                    v-model="form.body"
                    :placeholder="$t('inbox.reply.placeholder')"
                    class="max-h-40 min-h-11 flex-1 resize-none"
                    rows="2"
                    data-testid="inbox-reply-body"
                    @keydown.enter="sendOnShortcut"
                />
                <Button
                    type="submit"
                    :loading="form.processing"
                    :disabled="blank(form.body) || form.processing"
                    data-testid="inbox-reply-send"
                >
                    {{ $t('inbox.reply.send') }}
                </Button>
            </div>
            <InputError :message="form.errors.body" class="mt-2" />
        </form>
    </section>
</template>
