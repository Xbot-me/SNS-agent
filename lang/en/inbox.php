<?php

declare(strict_types=1);

return [
    'title' => 'Inbox',
    'check_now' => 'Check now',
    'search' => 'Search conversations...',
    'filters' => [
        'all' => 'All',
        'unread' => 'Unread',
    ],
    'kinds' => [
        'message' => 'Messages',
        'comment' => 'Comments',
        'mention' => 'Mentions',
        'review' => 'Reviews',
    ],
    'all_channels' => 'All channels',
    'empty' => [
        'title' => 'No conversations yet',
        'description' => 'Messages, comments, mentions and reviews from your connected channels show up here.',
    ],
    'no_results' => [
        'title' => 'No conversations match',
        'description' => 'Try another filter or clear the search.',
    ],
    'no_selection' => 'Pick a conversation to read and answer it.',
    'back' => 'Back',
    'you' => 'You',
    'open_on' => 'Open on :network',
    'on_post' => 'On your post',
    'reply' => [
        'placeholder' => 'Write a reply...',
        'send' => 'Send',
        'review_note' => 'Your reply shows publicly under the review. Answering again replaces it.',
    ],
    'notices' => [
        'missing' => 'Your inbox can\'t read all of :channel yet. To see its messages and comments here, reconnect it and allow access to them.',
        'failed' => 'Couldn\'t read :channel. If this keeps happening, reconnect it.',
        'open_channels' => 'Open channels',
    ],
    'errors' => [
        'reply_window' => ':network only lets you answer within 24 hours of the person\'s last message. Reply from the :network app instead.',
        'reconnect' => 'Couldn\'t send your reply. Reconnect your :network channel and try again.',
        'reply_failed' => 'Couldn\'t send your reply on :network. Try again.',
        'reply_failed_with_reason' => 'Couldn\'t send your reply on :network: :reason',
    ],
];
