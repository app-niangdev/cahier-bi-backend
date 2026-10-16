<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Enums\StatutDette;
use App\Helpers\ApiResponse;
use App\Models\Boutique;
use App\Models\Dette;
use App\Models\Paiement;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Tableau de bord de l'administrateur de la plateforme.
 */
class AdminDashboardController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function __invoke(): JsonResponse
    {
        // Inscriptions dont le numéro n'est pas encore confirmé : pas encore clientes, hors statistiques
        $tenants = Tenant::with('subscriptions')->where('approval_status', Tenant::APPROVED)->get();
        $statuses = $tenants
            ->map(fn (Tenant $tenant) => $this->subscriptions->statusForTenant($tenant) + ['tenant_active' => $tenant->state]);

        $monthStart = now()->startOfMonth();
        $roleIds = Role::pluck('id', 'name');
        $avecBoutique = Boutique::distinct()->pluck('tenant_id')->flip();

        return ApiResponse::success([
            'tenants' => [
                'total' => $statuses->count(),
                'active' => $statuses->where('tenant_active', true)->count(),
            ],
            'proprietaires' => User::where('role_id', $roleIds[RoleName::Proprietaire->value] ?? 0)->count(),
            'gerants' => User::where('role_id', $roleIds[RoleName::Gerant->value] ?? 0)->count(),
            'boutiques' => Boutique::count(),
            // Propriétaires inscrits sans boutique : l'administrateur doit les leur créer
            'sans_boutique' => $tenants->reject(fn (Tenant $t) => $avecBoutique->has($t->id))->count(),
            'subscriptions' => collect([
                SubscriptionService::ACTIVE, SubscriptionService::EXPIRING, SubscriptionService::EXPIRED, SubscriptionService::NONE,
            ])->mapWithKeys(fn ($state) => [$state => $statuses->where('state', $state)->count()]),
            // Encaissements d'abonnements saisis ce mois-ci
            'revenue_month' => (float) Subscription::where('created_at', '>=', $monthStart)->sum('amount'),
            'dettes_month' => Dette::where('created_at', '>=', $monthStart)->count(),
            // À relancer : échéance proche ou dépassée
            'attention' => $statuses
                ->whereIn('state', [SubscriptionService::EXPIRING, SubscriptionService::EXPIRED, SubscriptionService::NONE])
                ->sortBy(fn ($s) => $s['days_left'] ?? PHP_INT_MIN)
                ->take(8)
                ->values(),
            'tenant_stats' => $this->tenantStats($tenants),
        ]);
    }

    /**
     * Par propriétaire : boutiques, montant à récupérer et encaissé.
     */
    private function tenantStats(Collection $tenants): Collection
    {
        $boutiques = Boutique::selectRaw('tenant_id, COUNT(*) AS n')->groupBy('tenant_id')->pluck('n', 'tenant_id');

        $aRecuperer = Dette::query()
            ->join('boutiques', 'boutiques.id', '=', 'dettes.boutique_id')
            ->whereIn('dettes.statut', StatutDette::ouverts())
            ->selectRaw('boutiques.tenant_id, COALESCE(SUM(dettes.montant_restant), 0) AS total')
            ->groupBy('boutiques.tenant_id')
            ->pluck('total', 'tenant_id');

        $encaisse = Paiement::query()
            ->join('boutiques', 'boutiques.id', '=', 'paiements.boutique_id')
            ->selectRaw('boutiques.tenant_id, COALESCE(SUM(paiements.montant), 0) AS total')
            ->groupBy('boutiques.tenant_id')
            ->pluck('total', 'tenant_id');

        return $tenants
            ->map(fn (Tenant $tenant) => [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_active' => (bool) $tenant->state,
                'boutiques' => (int) ($boutiques[$tenant->id] ?? 0),
                'a_recuperer' => (float) ($aRecuperer[$tenant->id] ?? 0),
                'encaisse' => (float) ($encaisse[$tenant->id] ?? 0),
            ])
            ->sortBy([['a_recuperer', 'desc'], ['tenant_name', 'asc']])
            ->values();
    }
}
