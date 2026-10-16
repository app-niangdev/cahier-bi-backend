<?php

namespace App\Models;

use App\Enums\CanalRappel;
use App\Enums\StatutRappel;
use App\Enums\TonRappel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relance envoyée à un client pour une dette (WhatsApp ou SMS).
 */
class Rappel extends Model
{
    protected $fillable = [
        'dette_id', 'boutique_id', 'canal', 'ton', 'statut', 'envoye_le', 'message', 'erreur',
    ];

    protected function casts(): array
    {
        return [
            'canal' => CanalRappel::class,
            'ton' => TonRappel::class,
            'statut' => StatutRappel::class,
            'envoye_le' => 'datetime',
        ];
    }

    public function dette(): BelongsTo
    {
        return $this->belongsTo(Dette::class);
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
