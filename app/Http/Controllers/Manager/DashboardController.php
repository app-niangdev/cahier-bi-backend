<?php

namespace App\Http\Controllers\Manager;

use App\Enums\StatutDette;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicateurs de l'écran d'accueil : propriétaire (toutes ses boutiques, ou `boutique_id`)
 * et gérant (sa boutique). Avec `du` (et `au`, facultatif) : activité sur cette période.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'boutique_id' => ['nullable', 'integer'],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]);

        $boutiqueIds = $user->boutiquesAccessibles()
            ->when($filters['boutique_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        $dettes = fn () => Dette::whereIn('boutique_id', $boutiqueIds);
        $ouvertes = fn () => $dettes()->whereIn('statut', StatutDette::ouverts());
        $parStatut = fn (StatutDette $statut) => [
            'count' => $dettes()->where('statut', $statut)->count(),
            'total' => (float) $dettes()->where('statut', $statut)->sum('montant_restant'),
        ];

        $data = [
            'subscription' => $this->subscriptions->statusForTenant($user->tenant),
            'total_a_recuperer' => (float) $ouvertes()->sum('montant_restant'),
            'dettes' => [
                'en_attente' => $parStatut(StatutDette::EnAttente),
                'a_aujourdhui' => $parStatut(StatutDette::AAujourdhui),
                'en_retard' => $parStatut(StatutDette::EnRetard),
            ],
            'encaisse_aujourdhui' => (float) Paiement::whereIn('boutique_id', $boutiqueIds)
                ->whereDate('date_paiement', today())
                ->sum('montant'),
            'encaisse_mois' => (float) Paiement::whereIn('boutique_id', $boutiqueIds)
                ->whereBetween('date_paiement', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('montant'),
            'clients_count' => Client::whereIn('boutique_id', $boutiqueIds)->count(),
            'clients_debiteurs_count' => $ouvertes()->distinct()->count('client_id'),
            // Les plus urgentes : en retard d'abord, puis l'échéance la plus ancienne
            'a_relancer' => $ouvertes()
                ->with('client:id,nom,telephone')
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<=', today())
                ->orderBy('date_echeance')
                ->limit(5)
                ->get()
                ->map(fn (Dette $d) => [
                    'id' => $d->id,
                    'reference' => $d->reference,
                    'client' => $d->client?->only(['id', 'nom', 'telephone']),
                    'montant_restant' => (float) $d->montant_restant,
                    'date_echeance' => $d->date_echeance?->toDateString(),
                    'statut' => $d->statut,
                ]),
        ];

        if ($du = $filters['du'] ?? null) {
            $data['periode'] = $this->periode($boutiqueIds, $du, $filters['au'] ?? $du);
        }

        return ApiResponse::success($data);
    }

    /**
     * Activité entre deux dates incluses : encaissements (un versement réparti sur
     * plusieurs dettes compte une fois) et dettes notées, hors dettes annulées.
     */
    private function periode($boutiqueIds, string $du, string $au): array
    {
        $paiements = fn () => Paiement::whereIn('boutique_id', $boutiqueIds)
            ->whereBetween('date_paiement', [$du, $au]);
        $dettes = fn () => Dette::whereIn('boutique_id', $boutiqueIds)
            ->where('statut', '!=', StatutDette::Annulee)
            ->whereBetween('date_creation', [$du, $au]);

        return [
            'du' => $du,
            'au' => $au,
            'encaisse' => (float) $paiements()->sum('montant'),
            'versements_count' => $paiements()->distinct()->count('versement'),
            'dettes_count' => $dettes()->count(),
            'dettes_montant' => (float) $dettes()->sum('montant'),
        ];
    }
}
