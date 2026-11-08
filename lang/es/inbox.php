<?php

declare(strict_types=1);

return [
    'title' => 'Bandeja de entrada',
    'check_now' => 'Revisar ahora',
    'search' => 'Buscar conversaciones...',
    'filters' => [
        'all' => 'Todas',
        'unread' => 'No leídas',
    ],
    'kinds' => [
        'message' => 'Mensajes',
        'comment' => 'Comentarios',
        'mention' => 'Menciones',
        'review' => 'Reseñas',
    ],
    'all_channels' => 'Todos los canales',
    'empty' => [
        'title' => 'Aún no hay conversaciones',
        'description' => 'Los mensajes, comentarios, menciones y reseñas de tus canales conectados aparecen aquí.',
    ],
    'no_results' => [
        'title' => 'Ninguna conversación coincide',
        'description' => 'Prueba otro filtro o borra la búsqueda.',
    ],
    'no_selection' => 'Elige una conversación para leerla y responder.',
    'back' => 'Volver',
    'you' => 'Tú',
    'open_on' => 'Abrir en :network',
    'on_post' => 'En tu publicación',
    'reply' => [
        'placeholder' => 'Escribe una respuesta...',
        'send' => 'Enviar',
        'review_note' => 'Tu respuesta se ve públicamente bajo la reseña. Responder de nuevo la reemplaza.',
    ],
    'notices' => [
        'missing' => 'Tu bandeja de entrada aún no puede leer todo de :channel. Para ver aquí sus mensajes y comentarios, vuelve a conectarlo y permite el acceso a ellos.',
        'failed' => 'No se pudo leer :channel. Si sigue pasando, vuelve a conectarlo.',
        'open_channels' => 'Abrir canales',
    ],
    'errors' => [
        'reply_window' => ':network solo permite responder dentro de las 24 horas siguientes al último mensaje de la persona. Responde desde la app de :network.',
        'reconnect' => 'No se pudo enviar tu respuesta. Vuelve a conectar tu canal de :network e inténtalo de nuevo.',
        'reply_failed' => 'No se pudo enviar tu respuesta en :network. Inténtalo de nuevo.',
        'reply_failed_with_reason' => 'No se pudo enviar tu respuesta en :network: :reason',
    ],
];
