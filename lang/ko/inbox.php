<?php

declare(strict_types=1);

return [
    'title' => '받은편지함',
    'check_now' => '지금 확인',
    'search' => '대화 검색...',
    'filters' => [
        'all' => '전체',
        'unread' => '읽지 않음',
    ],
    'kinds' => [
        'message' => '메시지',
        'comment' => '댓글',
        'mention' => '멘션',
        'review' => '리뷰',
    ],
    'all_channels' => '모든 채널',
    'empty' => [
        'title' => '아직 대화가 없습니다',
        'description' => '연결된 채널의 메시지, 댓글, 멘션, 리뷰가 여기에 표시됩니다.',
    ],
    'no_results' => [
        'title' => '일치하는 대화가 없습니다',
        'description' => '다른 필터를 사용하거나 검색어를 지우세요.',
    ],
    'no_selection' => '대화를 선택해 읽고 답장하세요.',
    'back' => '뒤로',
    'you' => '나',
    'open_on' => ':network에서 열기',
    'on_post' => '내 게시물에 달림',
    'reply' => [
        'placeholder' => '답장을 입력하세요...',
        'send' => '보내기',
        'review_note' => '답장은 리뷰 아래에 공개로 표시됩니다. 다시 답장하면 기존 답장을 대체합니다.',
    ],
    'notices' => [
        'missing' => '받은편지함에서 아직 :channel의 모든 내용을 읽을 수 없습니다. 메시지와 댓글을 여기서 보려면 채널을 다시 연결하고 접근을 허용하세요.',
        'failed' => ':channel을(를) 읽지 못했습니다. 계속 발생하면 다시 연결하세요.',
        'open_channels' => '채널 열기',
    ],
    'errors' => [
        'reply_window' => ':network에서는 상대방의 마지막 메시지 후 24시간 이내에만 답장할 수 있습니다. :network 앱에서 답장하세요.',
        'reconnect' => '답장을 보내지 못했습니다. :network 채널을 다시 연결한 후 다시 시도하세요.',
        'reply_failed' => ':network에서 답장을 보내지 못했습니다. 다시 시도하세요.',
        'reply_failed_with_reason' => ':network에서 답장을 보내지 못했습니다: :reason',
    ],
];
