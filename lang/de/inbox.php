<?php

declare(strict_types=1);

return [
    'title' => 'Posteingang',
    'check_now' => 'Jetzt prüfen',
    'search' => 'Unterhaltungen suchen...',
    'filters' => [
        'all' => 'Alle',
        'unread' => 'Ungelesen',
    ],
    'kinds' => [
        'message' => 'Nachrichten',
        'comment' => 'Kommentare',
        'mention' => 'Erwähnungen',
        'review' => 'Bewertungen',
    ],
    'all_channels' => 'Alle Kanäle',
    'empty' => [
        'title' => 'Noch keine Unterhaltungen',
        'description' => 'Nachrichten, Kommentare, Erwähnungen und Bewertungen deiner verbundenen Kanäle erscheinen hier.',
    ],
    'no_results' => [
        'title' => 'Keine passenden Unterhaltungen',
        'description' => 'Versuche einen anderen Filter oder leere die Suche.',
    ],
    'no_selection' => 'Wähle eine Unterhaltung, um sie zu lesen und zu beantworten.',
    'back' => 'Zurück',
    'you' => 'Du',
    'open_on' => 'Auf :network öffnen',
    'on_post' => 'Zu deinem Beitrag',
    'reply' => [
        'placeholder' => 'Antwort schreiben...',
        'send' => 'Senden',
        'review_note' => 'Deine Antwort erscheint öffentlich unter der Bewertung. Eine neue Antwort ersetzt sie.',
    ],
    'notices' => [
        'missing' => 'Dein Posteingang kann :channel noch nicht vollständig lesen. Um Nachrichten und Kommentare hier zu sehen, verbinde den Kanal neu und erlaube den Zugriff darauf.',
        'failed' => ':channel konnte nicht gelesen werden. Wenn das weiter passiert, verbinde den Kanal neu.',
        'open_channels' => 'Kanäle öffnen',
    ],
    'errors' => [
        'reply_window' => ':network erlaubt Antworten nur innerhalb von 24 Stunden nach der letzten Nachricht der Person. Antworte stattdessen in der :network-App.',
        'reconnect' => 'Deine Antwort konnte nicht gesendet werden. Verbinde deinen :network-Kanal neu und versuche es noch einmal.',
        'reply_failed' => 'Deine Antwort auf :network konnte nicht gesendet werden. Versuche es noch einmal.',
        'reply_failed_with_reason' => 'Deine Antwort auf :network konnte nicht gesendet werden: :reason',
    ],
];
