<?php

namespace App\Models;

use App\Enums\StatutDette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client d'une boutique, qui prend à crédit.
 */
class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'boutique_id', 'nom', 'telephone', 'whatsapp', 'photo_path', 'adresse', 'note',
    ];

    protected $hidden = ['deleted_at'];

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function dettes(): HasMany
    {
        return $this->hasMany(Dette::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /** Somme restant due sur ses dettes non soldées. */
    public function totalDu(): float
    {
        return (float) $this->dettes()->whereIn('statut', StatutDette::ouverts())->sum('montant_restant');
    }
}
