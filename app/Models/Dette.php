<?php

namespace App\Models;

use App\Enums\StatutDette;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dette d'un client envers une boutique.
 *
 * `montant_paye` et `montant_restant` découlent des paiements : ils sont recalculés par
 * `recalculer()` à chaque paiement enregistré ou annulé, jamais saisis à la main.
 */
class Dette extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'boutique_id', 'client_id', 'reference', 'montant', 'montant_paye', 'montant_restant',
        'date_creation', 'date_echeance', 'statut', 'note',
    ];

    protected $hidden = ['deleted_at'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'montant_paye' => 'decimal:2',
            'montant_restant' => 'decimal:2',
            'date_creation' => 'date:Y-m-d',
            'date_echeance' => 'date:Y-m-d',
            'statut' => StatutDette::class,
        ];
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class);
    }

    /**
     * Recalcule le payé et le restant à partir des paiements, puis le statut.
     * Une dette annulée le reste.
     */
    public function recalculer(): void
    {
        $paye = round((float) $this->paiements()->sum('montant'), 2);
        $restant = max(0, round((float) $this->montant - $paye, 2));

        $this->montant_paye = $paye;
        $this->montant_restant = $restant;

        if ($this->statut !== StatutDette::Annulee) {
            $this->statut = $restant <= 0 ? StatutDette::Payee : self::statutSelonEcheance($this->date_echeance);
        }

        $this->save();
    }

    /** Statut d'une dette non soldée, d'après son échéance. */
    public static function statutSelonEcheance(?CarbonInterface $echeance, ?CarbonInterface $aujourdhui = null): StatutDette
    {
        $aujourdhui ??= today();

        return match (true) {
            $echeance === null, $echeance->gt($aujourdhui) => StatutDette::EnAttente,
            $echeance->isSameDay($aujourdhui) => StatutDette::AAujourdhui,
            default => StatutDette::EnRetard,
        };
    }
}
