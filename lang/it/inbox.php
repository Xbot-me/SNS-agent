<?php

declare(strict_types=1);

return [
    'title' => 'Posta in arrivo',
    'check_now' => 'Controlla ora',
    'search' => 'Cerca conversazioni...',
    'filters' => [
        'all' => 'Tutte',
        'unread' => 'Non lette',
    ],
    'kinds' => [
        'message' => 'Messaggi',
        'comment' => 'Commenti',
        'mention' => 'Menzioni',
        'review' => 'Recensioni',
    ],
    'all_channels' => 'Tutti i canali',
    'empty' => [
        'title' => 'Ancora nessuna conversazione',
        'description' => 'Messaggi, commenti, menzioni e recensioni dei tuoi canali collegati compaiono qui.',
    ],
    'no_results' => [
        'title' => 'Nessuna conversazione corrisponde',
        'description' => 'Prova un altro filtro o cancella la ricerca.',
    ],
    'no_selection' => 'Scegli una conversazione per leggerla e rispondere.',
    'back' => 'Indietro',
    'you' => 'Tu',
    'open_on' => 'Apri su :network',
    'on_post' => 'Sul tuo post',
    'reply' => [
        'placeholder' => 'Scrivi una risposta...',
        'send' => 'Invia',
        'review_note' => 'La tua risposta è visibile a tutti sotto la recensione. Rispondere di nuovo la sostituisce.',
    ],
    'notices' => [
        'missing' => 'La tua posta in arrivo non riesce ancora a leggere tutto di :channel. Per vedere qui messaggi e commenti, ricollegalo e consenti l’accesso.',
        'failed' => 'Impossibile leggere :channel. Se continua a succedere, ricollegalo.',
        'open_channels' => 'Apri canali',
    ],
    'errors' => [
        'reply_window' => ':network permette di rispondere solo entro 24 ore dall’ultimo messaggio della persona. Rispondi dall’app di :network.',
        'reconnect' => 'Impossibile inviare la risposta. Ricollega il tuo canale :network e riprova.',
        'reply_failed' => 'Impossibile inviare la risposta su :network. Riprova.',
        'reply_failed_with_reason' => 'Impossibile inviare la risposta su :network: :reason',
    ],
];
