<?php

declare(strict_types=1);

return [
    'title' => 'Gelen kutusu',
    'check_now' => 'Şimdi kontrol et',
    'search' => 'Sohbetlerde ara...',
    'filters' => [
        'all' => 'Tümü',
        'unread' => 'Okunmamış',
    ],
    'kinds' => [
        'message' => 'Mesajlar',
        'comment' => 'Yorumlar',
        'mention' => 'Bahsetmeler',
        'review' => 'Değerlendirmeler',
    ],
    'all_channels' => 'Tüm kanallar',
    'empty' => [
        'title' => 'Henüz sohbet yok',
        'description' => 'Bağlı kanallarınızdan gelen mesajlar, yorumlar, bahsetmeler ve değerlendirmeler burada görünür.',
    ],
    'no_results' => [
        'title' => 'Eşleşen sohbet yok',
        'description' => 'Başka bir filtre deneyin veya aramayı temizleyin.',
    ],
    'no_selection' => 'Okumak ve yanıtlamak için bir sohbet seçin.',
    'back' => 'Geri',
    'you' => 'Siz',
    'open_on' => ':network üzerinde aç',
    'on_post' => 'Gönderinizde',
    'reply' => [
        'placeholder' => 'Bir yanıt yazın...',
        'send' => 'Gönder',
        'review_note' => 'Yanıtınız değerlendirmenin altında herkese açık görünür. Yeniden yanıtlamak öncekinin yerini alır.',
    ],
    'notices' => [
        'missing' => 'Gelen kutunuz :channel kanalının tamamını henüz okuyamıyor. Mesajlarını ve yorumlarını burada görmek için kanalı yeniden bağlayın ve erişime izin verin.',
        'failed' => ':channel okunamadı. Bu devam ederse kanalı yeniden bağlayın.',
        'open_channels' => 'Kanalları aç',
    ],
    'errors' => [
        'reply_window' => ':network yalnızca kişinin son mesajından sonraki 24 saat içinde yanıt vermenize izin verir. Bunun yerine :network uygulamasından yanıtlayın.',
        'reconnect' => 'Yanıtınız gönderilemedi. :network kanalınızı yeniden bağlayıp tekrar deneyin.',
        'reply_failed' => 'Yanıtınız :network üzerinde gönderilemedi. Tekrar deneyin.',
        'reply_failed_with_reason' => 'Yanıtınız :network üzerinde gönderilemedi: :reason',
    ],
];
