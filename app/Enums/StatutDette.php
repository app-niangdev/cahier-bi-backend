<?php

namespace App\Enums;

/**
 * « À aujourd'hui » et « en retard » dépendent de la date d'échéance :
 * recalculés chaque jour par la commande `dettes:actualiser-statuts`.
 */
enum StatutDette: string
{
    case EnAttente = 'en_attente';
    case AAujourdhui = 'a_aujourdhui';
    case EnRetard = 'en_retard';
    case Payee = 'payee';
    case Annulee = 'annulee';

    /** Dettes encore à récupérer. */
    public static function ouverts(): array
    {
        return [self::EnAttente->value, self::AAujourdhui->value, self::EnRetard->value];
    }

    public function estOuvert(): bool
    {
        return in_array($this->value, self::ouverts(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::AAujourdhui => 'À payer aujourd\'hui',
            self::EnRetard => 'En retard',
            self::Payee => 'Payée',
            self::Annulee => 'Annulée',
        };
    }
}
