<?php

declare(strict_types=1);

return [
    'title' => 'Skrzynka',
    'check_now' => 'Sprawdź teraz',
    'search' => 'Szukaj rozmów...',
    'filters' => [
        'all' => 'Wszystkie',
        'unread' => 'Nieprzeczytane',
    ],
    'kinds' => [
        'message' => 'Wiadomości',
        'comment' => 'Komentarze',
        'mention' => 'Wzmianki',
        'review' => 'Opinie',
    ],
    'all_channels' => 'Wszystkie kanały',
    'empty' => [
        'title' => 'Brak rozmów',
        'description' => 'Tutaj pojawiają się wiadomości, komentarze, wzmianki i opinie z połączonych kanałów.',
    ],
    'no_results' => [
        'title' => 'Brak pasujących rozmów',
        'description' => 'Wybierz inny filtr lub wyczyść wyszukiwanie.',
    ],
    'no_selection' => 'Wybierz rozmowę, aby ją przeczytać i odpowiedzieć.',
    'back' => 'Wstecz',
    'you' => 'Ty',
    'open_on' => 'Otwórz w :network',
    'on_post' => 'Pod Twoim postem',
    'reply' => [
        'placeholder' => 'Napisz odpowiedź...',
        'send' => 'Wyślij',
        'review_note' => 'Odpowiedź będzie widoczna publicznie pod opinią. Kolejna odpowiedź ją zastąpi.',
    ],
    'notices' => [
        'missing' => 'Skrzynka nie ma jeszcze pełnego dostępu do :channel. Aby widzieć tu wiadomości i komentarze, połącz kanał ponownie i zezwól na dostęp do nich.',
        'failed' => 'Nie udało się odczytać :channel. Jeśli to się powtarza, połącz kanał ponownie.',
        'open_channels' => 'Otwórz kanały',
    ],
    'errors' => [
        'reply_window' => ':network pozwala odpowiadać tylko w ciągu 24 godzin od ostatniej wiadomości tej osoby. Odpowiedz w aplikacji :network.',
        'reconnect' => 'Nie udało się wysłać odpowiedzi. Połącz ponownie kanał :network i spróbuj jeszcze raz.',
        'reply_failed' => 'Nie udało się wysłać odpowiedzi w :network. Spróbuj jeszcze raz.',
        'reply_failed_with_reason' => 'Nie udało się wysłać odpowiedzi w :network: :reason',
    ],
];
