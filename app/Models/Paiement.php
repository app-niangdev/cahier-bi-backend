<?php

namespace App\Models;

use App\Enums\MethodePaiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Part d'un versement du client imputée sur une dette. Un versement réparti sur plusieurs
 * dettes produit une ligne par dette, reliées par le même `versement` (uuid).
 */
class Paiement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dette_id', 'boutique_id', 'client_id', 'enregistre_par', 'versement', 'montant', 'date_paiement',
        'methode', 'reference', 'note',
    ];

    protected $hidden = ['deleted_at'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'date:Y-m-d',
            'methode' => MethodePaiement::class,
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par')->withTrashed();
    }
}
