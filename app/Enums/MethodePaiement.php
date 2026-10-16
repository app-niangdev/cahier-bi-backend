<?php

namespace App\Enums;

enum MethodePaiement: string
{
    case Especes = 'especes';
    case Wave = 'wave';
    case OrangeMoney = 'orange_money';
    case FreeMoney = 'free_money';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Especes => 'Espèces',
            self::Wave => 'Wave',
            self::OrangeMoney => 'Orange Money',
            self::FreeMoney => 'Free Money',
            self::Autre => 'Autre',
        };
    }
}
