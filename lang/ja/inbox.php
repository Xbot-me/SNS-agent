<?php

declare(strict_types=1);

return [
    'title' => '受信箱',
    'check_now' => '今すぐ確認',
    'search' => '会話を検索...',
    'filters' => [
        'all' => 'すべて',
        'unread' => '未読',
    ],
    'kinds' => [
        'message' => 'メッセージ',
        'comment' => 'コメント',
        'mention' => 'メンション',
        'review' => 'クチコミ',
    ],
    'all_channels' => 'すべてのチャンネル',
    'empty' => [
        'title' => 'まだ会話はありません',
        'description' => '接続したチャンネルのメッセージ、コメント、メンション、クチコミがここに表示されます。',
    ],
    'no_results' => [
        'title' => '一致する会話はありません',
        'description' => '別のフィルターを試すか、検索をクリアしてください。',
    ],
    'no_selection' => '会話を選んで読んだり返信したりしましょう。',
    'back' => '戻る',
    'you' => 'あなた',
    'open_on' => ':network で開く',
    'on_post' => 'あなたの投稿へ',
    'reply' => [
        'placeholder' => '返信を入力...',
        'send' => '送信',
        'review_note' => '返信はクチコミの下に公開で表示されます。もう一度返信すると置き換わります。',
    ],
    'notices' => [
        'missing' => '受信箱はまだ :channel のすべてを読み取れません。メッセージとコメントをここで見るには、チャンネルを再接続してアクセスを許可してください。',
        'failed' => ':channel を読み取れませんでした。繰り返し起きる場合は再接続してください。',
        'open_channels' => 'チャンネルを開く',
    ],
    'errors' => [
        'reply_window' => ':network では相手の最後のメッセージから24時間以内しか返信できません。:network アプリから返信してください。',
        'reconnect' => '返信を送信できませんでした。:network チャンネルを再接続して、もう一度お試しください。',
        'reply_failed' => ':network で返信を送信できませんでした。もう一度お試しください。',
        'reply_failed_with_reason' => ':network で返信を送信できませんでした: :reason',
    ],
];
