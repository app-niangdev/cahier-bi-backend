<?php

namespace App\Enums;

/**
 * Rôles de la plateforme (colonne `roles.name`).
 *
 * ADMIN : administrateur (application web), crée les boutiques des propriétaires.
 * PROPRIETAIRE : boutiquier abonné, gère ses gérants (application mobile).
 * GERANT : employé d'une seule boutique, ajouté par le propriétaire (application mobile).
 */
enum RoleName: string
{
    case Admin = 'ADMIN';
    case Proprietaire = 'PROPRIETAIRE';
    case Gerant = 'GERANT';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Proprietaire => 'Propriétaire',
            self::Gerant => 'Gérant',
        };
    }
}
