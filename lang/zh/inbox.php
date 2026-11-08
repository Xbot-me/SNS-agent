<?php

declare(strict_types=1);

return [
    'title' => '收件箱',
    'check_now' => '立即检查',
    'search' => '搜索对话...',
    'filters' => [
        'all' => '全部',
        'unread' => '未读',
    ],
    'kinds' => [
        'message' => '消息',
        'comment' => '评论',
        'mention' => '提及',
        'review' => '评价',
    ],
    'all_channels' => '所有渠道',
    'empty' => [
        'title' => '还没有对话',
        'description' => '已连接渠道的消息、评论、提及和评价会显示在这里。',
    ],
    'no_results' => [
        'title' => '没有匹配的对话',
        'description' => '换个筛选条件或清除搜索。',
    ],
    'no_selection' => '选择一个对话来阅读和回复。',
    'back' => '返回',
    'you' => '你',
    'open_on' => '在 :network 中打开',
    'on_post' => '你的帖子下',
    'reply' => [
        'placeholder' => '输入回复...',
        'send' => '发送',
        'review_note' => '你的回复会公开显示在评价下方。再次回复会替换它。',
    ],
    'notices' => [
        'missing' => '收件箱还不能读取 :channel 的全部内容。要在这里查看它的消息和评论，请重新连接并允许访问。',
        'failed' => '无法读取 :channel。如果一直出现，请重新连接。',
        'open_channels' => '打开渠道',
    ],
    'errors' => [
        'reply_window' => ':network 只允许在对方最后一条消息后 24 小时内回复。请改用 :network 应用回复。',
        'reconnect' => '回复发送失败。请重新连接你的 :network 渠道后重试。',
        'reply_failed' => '在 :network 上发送回复失败。请重试。',
        'reply_failed_with_reason' => '在 :network 上发送回复失败：:reason',
    ],
];
