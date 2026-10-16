<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\StatutBoutique;
use App\Enums\StatutDette;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Boutique d'un propriétaire (tenant). Seul l'administrateur en crée.
 */
class Boutique extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'nom', 'slug', 'telephone', 'whatsapp',
        'adresse', 'quartier', 'ville', 'logo_path', 'statut',
    ];

    protected $hidden = ['deleted_at'];

    protected function casts(): array
    {
        return [
            'statut' => StatutBoutique::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Gérants affectés à la boutique (comptes actifs ou désactivés, hors supprimés). */
    public function gerants(): HasMany
    {
        return $this->hasMany(User::class)
            ->whereHas('role', fn ($q) => $q->where('name', RoleName::Gerant->value));
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function dettes(): HasMany
    {
        return $this->hasMany(Dette::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class);
    }

    public function estActive(): bool
    {
        return $this->statut === StatutBoutique::Actif;
    }

    /** Somme restant due sur les dettes non soldées. */
    public function totalARecuperer(): float
    {
        return (float) $this->dettes()->whereIn('statut', StatutDette::ouverts())->sum('montant_restant');
    }
}
