<?php

declare(strict_types=1);

return [
    'title' => 'Inbox',
    'check_now' => 'Nu controleren',
    'search' => 'Gesprekken zoeken...',
    'filters' => [
        'all' => 'Alle',
        'unread' => 'Ongelezen',
    ],
    'kinds' => [
        'message' => 'Berichten',
        'comment' => 'Reacties',
        'mention' => 'Vermeldingen',
        'review' => 'Reviews',
    ],
    'all_channels' => 'Alle kanalen',
    'empty' => [
        'title' => 'Nog geen gesprekken',
        'description' => 'Berichten, reacties, vermeldingen en reviews van je gekoppelde kanalen verschijnen hier.',
    ],
    'no_results' => [
        'title' => 'Geen gesprekken gevonden',
        'description' => 'Probeer een ander filter of wis de zoekopdracht.',
    ],
    'no_selection' => 'Kies een gesprek om het te lezen en te beantwoorden.',
    'back' => 'Terug',
    'you' => 'Jij',
    'open_on' => 'Openen op :network',
    'on_post' => 'Bij je bericht',
    'reply' => [
        'placeholder' => 'Schrijf een antwoord...',
        'send' => 'Versturen',
        'review_note' => 'Je antwoord is openbaar zichtbaar onder de review. Opnieuw antwoorden vervangt het.',
    ],
    'notices' => [
        'missing' => 'Je inbox kan nog niet alles van :channel lezen. Koppel het kanaal opnieuw en geef toegang om de berichten en reacties hier te zien.',
        'failed' => ':channel kon niet worden gelezen. Koppel het opnieuw als dit blijft gebeuren.',
        'open_channels' => 'Kanalen openen',
    ],
    'errors' => [
        'reply_window' => ':network laat je alleen antwoorden binnen 24 uur na het laatste bericht van die persoon. Antwoord vanuit de :network-app.',
        'reconnect' => 'Je antwoord kon niet worden verstuurd. Koppel je :network-kanaal opnieuw en probeer het nog eens.',
        'reply_failed' => 'Je antwoord op :network kon niet worden verstuurd. Probeer het nog eens.',
        'reply_failed_with_reason' => 'Je antwoord op :network kon niet worden verstuurd: :reason',
    ],
];
