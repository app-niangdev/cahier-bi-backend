<?php

return [
    // Nombre de jours avant l'échéance à partir duquel on prévient les utilisateurs à la connexion
    'warning_days' => (int) env('SUBSCRIPTION_WARNING_DAYS', 5),

    // Période d'essai offerte à l'inscription d'un propriétaire (0 = aucune : il est bloqué
    // tant qu'un administrateur n'a pas saisi d'abonnement)
    'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 14),

    // Forfait offert à l'inscription depuis l'application (à défaut, période d'essai sans forfait)
    'signup_plan' => env('SUBSCRIPTION_SIGNUP_PLAN', 'Gratuit'),

    // Gérants autorisés par boutique pendant l'essai (abonnement sans forfait)
    'trial_max_gerants_par_boutique' => (int) env('SUBSCRIPTION_TRIAL_MAX_GERANTS', 1),

    'default_currency' => env('SUBSCRIPTION_CURRENCY', 'XOF'),

    // Contact affiché aux propriétaires pour renouveler (application mobile)
    'contact' => [
        'name' => env('SUBSCRIPTION_CONTACT_NAME', 'Administrateur Cahier Bi'),
        'phone' => env('SUBSCRIPTION_CONTACT_PHONE'),
        'whatsapp' => env('SUBSCRIPTION_CONTACT_WHATSAPP'),
        'payment_methods' => env('SUBSCRIPTION_PAYMENT_METHODS', 'Wave, Orange Money'),
    ],
];
