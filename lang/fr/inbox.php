<?php

declare(strict_types=1);

return [
    'title' => 'Messagerie',
    'check_now' => 'Vérifier',
    'search' => 'Rechercher des conversations...',
    'filters' => [
        'all' => 'Toutes',
        'unread' => 'Non lues',
    ],
    'kinds' => [
        'message' => 'Messages',
        'comment' => 'Commentaires',
        'mention' => 'Mentions',
        'review' => 'Avis',
    ],
    'all_channels' => 'Tous les canaux',
    'empty' => [
        'title' => 'Aucune conversation pour l’instant',
        'description' => 'Les messages, commentaires, mentions et avis de vos canaux connectés s’affichent ici.',
    ],
    'no_results' => [
        'title' => 'Aucune conversation ne correspond',
        'description' => 'Essayez un autre filtre ou effacez la recherche.',
    ],
    'no_selection' => 'Choisissez une conversation pour la lire et y répondre.',
    'back' => 'Retour',
    'you' => 'Vous',
    'open_on' => 'Ouvrir sur :network',
    'on_post' => 'Sur votre publication',
    'reply' => [
        'placeholder' => 'Écrire une réponse...',
        'send' => 'Envoyer',
        'review_note' => 'Votre réponse s’affiche publiquement sous l’avis. Répondre à nouveau la remplace.',
    ],
    'notices' => [
        'missing' => 'Votre messagerie ne peut pas encore tout lire de :channel. Pour voir ici ses messages et commentaires, reconnectez-le et autorisez-en l’accès.',
        'failed' => 'Impossible de lire :channel. Si cela continue, reconnectez-le.',
        'open_channels' => 'Ouvrir les canaux',
    ],
    'errors' => [
        'reply_window' => ':network ne permet de répondre que dans les 24 heures suivant le dernier message de la personne. Répondez depuis l’app :network.',
        'reconnect' => 'Impossible d’envoyer votre réponse. Reconnectez votre canal :network et réessayez.',
        'reply_failed' => 'Impossible d’envoyer votre réponse sur :network. Réessayez.',
        'reply_failed_with_reason' => 'Impossible d’envoyer votre réponse sur :network : :reason',
    ],
];
