<?php

declare(strict_types=1);

return [
    'title' => 'Caixa de entrada',
    'check_now' => 'Verificar agora',
    'search' => 'Buscar conversas...',
    'filters' => [
        'all' => 'Todas',
        'unread' => 'Não lidas',
    ],
    'kinds' => [
        'message' => 'Mensagens',
        'comment' => 'Comentários',
        'mention' => 'Menções',
        'review' => 'Avaliações',
    ],
    'all_channels' => 'Todos os canais',
    'empty' => [
        'title' => 'Nenhuma conversa ainda',
        'description' => 'Mensagens, comentários, menções e avaliações dos seus canais conectados aparecem aqui.',
    ],
    'no_results' => [
        'title' => 'Nenhuma conversa encontrada',
        'description' => 'Tente outro filtro ou limpe a busca.',
    ],
    'no_selection' => 'Escolha uma conversa para ler e responder.',
    'back' => 'Voltar',
    'you' => 'Você',
    'open_on' => 'Abrir no :network',
    'on_post' => 'No seu post',
    'reply' => [
        'placeholder' => 'Escreva uma resposta...',
        'send' => 'Enviar',
        'review_note' => 'Sua resposta aparece publicamente abaixo da avaliação. Responder de novo substitui a anterior.',
    ],
    'notices' => [
        'missing' => 'Sua caixa de entrada ainda não consegue ler tudo de :channel. Para ver as mensagens e os comentários aqui, reconecte o canal e permita o acesso a eles.',
        'failed' => 'Não foi possível ler :channel. Se continuar acontecendo, reconecte o canal.',
        'open_channels' => 'Abrir canais',
    ],
    'errors' => [
        'reply_window' => 'O :network só permite responder até 24 horas depois da última mensagem da pessoa. Responda pelo app do :network.',
        'reconnect' => 'Não foi possível enviar sua resposta. Reconecte seu canal do :network e tente de novo.',
        'reply_failed' => 'Não foi possível enviar sua resposta no :network. Tente de novo.',
        'reply_failed_with_reason' => 'Não foi possível enviar sua resposta no :network: :reason',
    ],
];
