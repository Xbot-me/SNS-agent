export type InboxKind = 'message' | 'comment' | 'mention' | 'review';

export interface InboxChannelRef {
    id: string | null;
    name: string | null;
    platform: string | null;
    avatar_url: string | null;
}

export interface InboxConversationSummary {
    id: string;
    kind: InboxKind;
    platform: string;
    network_name: string;
    participant_name: string | null;
    participant_username: string | null;
    participant_avatar_url: string | null;
    subject: string | null;
    last_message_excerpt: string | null;
    last_message_at: string | null;
    is_unread: boolean;
    rating: number | null;
    channel: InboxChannelRef;
}

export interface InboxAttachment {
    type: 'image' | 'video' | 'file';
    url: string;
}

export interface InboxMessage {
    id: string;
    body: string | null;
    author_name: string | null;
    author_username: string | null;
    author_avatar_url: string | null;
    is_own: boolean;
    attachments: InboxAttachment[];
    sent_at: string;
}

export interface InboxConversationDetail extends InboxConversationSummary {
    permalink: string | null;
    messages: InboxMessage[];
}

export interface InboxChannel {
    id: string;
    name: string;
    platform: string;
    avatar_url: string | null;
    is_connected: boolean;
    synced_at: string | null;
    missing: InboxKind[];
    error: string | null;
}

export interface InboxFilters {
    kind: InboxKind | null;
    channel: string | null;
    unread: boolean;
    search: string;
}
