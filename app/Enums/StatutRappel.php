<?php

namespace App\Enums;

enum StatutRappel: string
{
    case EnAttente = 'en_attente';
    case Envoye = 'envoye';
    case Echoue = 'echoue';
}
