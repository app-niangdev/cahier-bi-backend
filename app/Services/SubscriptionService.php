<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcule l'état d'abonnement des propriétaires (tenants) et l'accès qui en découle,
 * pour eux et pour leurs gérants.
 *
 * États : active | expiring (échéance dans moins de `warning_days` jours) | expired | none.
 * Les abonnements qui se suivent sans interruption (renouvellement anticipé) sont chaînés :
 * l'échéance retenue est la fin de la période couverte en continu à partir d'aujourd'hui.
 */
class SubscriptionService
{
    public const ACTIVE = 'active';
    public const EXPIRING = 'expiring';
    public const EXPIRED = 'expired';
    public const NONE = 'none';

    public function warningDays(): int
    {
        return (int) config('subscriptions.warning_days', 5);
    }

    /**
     * @return array{tenant_id:int, tenant_name:string, state:string, plan:?string,
     *               starts_at:?string, ends_at:?string, days_left:?int}
     */
    public function statusForTenant(Tenant $tenant): array
    {
        $today = CarbonImmutable::today();
        $subscriptions = $tenant->relationLoaded('subscriptions')
            ? $tenant->subscriptions
            : $tenant->subscriptions()->get();

        $current = $subscriptions->first(
            fn (Subscription $s) => $s->starts_at->lte($today) && $s->ends_at->gte($today)
        );

        $base = [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
        ];

        if (!$current) {
            // Dernier abonnement échu, pour afficher les détails de l'expiration
            $last = $subscriptions
                ->filter(fn (Subscription $s) => $s->ends_at->lt($today))
                ->sortByDesc('ends_at')
                ->first();

            return $base + [
                'state' => $last ? self::EXPIRED : self::NONE,
                'plan' => $last?->plan,
                'starts_at' => $last?->starts_at->toDateString(),
                'ends_at' => $last?->ends_at->toDateString(),
                'days_left' => $last ? -(int) abs($last->ends_at->diffInDays($today)) : null,
            ];
        }

        $coverageEnd = $this->coverageEnd($subscriptions, $current);
        $daysLeft = (int) abs($today->diffInDays($coverageEnd));

        return $base + [
            'state' => $daysLeft <= $this->warningDays() ? self::EXPIRING : self::ACTIVE,
            'plan' => $current->plan,
            'starts_at' => $current->starts_at->toDateString(),
            'ends_at' => $coverageEnd->toDateString(),
            'days_left' => $daysLeft,
        ];
    }

    /**
     * Gérants autorisés dans chaque boutique du propriétaire, d'après l'abonnement en cours :
     * le forfait choisi, ou la limite de l'essai (sans forfait). Null = illimité ; 0 sans abonnement.
     */
    public function maxGerantsParBoutique(Tenant $tenant): ?int
    {
        $today = CarbonImmutable::today();

        $current = $tenant->subscriptions()
            ->with('subscriptionPlan')
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->latest('starts_at')
            ->first();

        if (!$current) {
            return 0;
        }

        if (!$current->subscriptionPlan) {
            return (int) config('subscriptions.trial_max_gerants_par_boutique', 1);
        }

        return $current->subscriptionPlan->max_gerants_par_boutique;
    }

    public function isAccessible(Tenant $tenant): bool
    {
        return in_array($this->statusForTenant($tenant)['state'], [self::ACTIVE, self::EXPIRING], true);
    }

    /**
     * Refuse l'accès d'une entreprise désactivée ou dont l'abonnement n'est plus valide.
     *
     * @throws ApiException
     */
    public function ensureAccessible(?Tenant $tenant): void
    {
        if (!$tenant || !$tenant->state) {
            throw new ApiException(
                'Votre entreprise est désactivée. Contactez l\'administrateur de la plateforme.',
                'TENANT_DISABLED',
                403,
            );
        }

        $status = $this->statusForTenant($tenant);
        if (!in_array($status['state'], [self::ACTIVE, self::EXPIRING], true)) {
            throw new ApiException(
                $status['state'] === self::EXPIRED
                    ? 'Votre abonnement a expiré le ' . CarbonImmutable::parse($status['ends_at'])->format('d/m/Y')
                        . '. Contactez l\'administrateur pour le renouveler.'
                    : 'Vous n\'avez pas d\'abonnement actif. Contactez l\'administrateur de la plateforme.',
                'SUBSCRIPTION_EXPIRED',
                403,
                ['subscription' => $status],
            );
        }
    }

    /**
     * Accès d'un utilisateur de l'application mobile : compte du propriétaire actif et abonné ;
     * pour un gérant, boutique active en plus.
     *
     * @throws ApiException
     */
    public function ensureBoutiquierAccess(User $user): void
    {
        $this->ensureAccessible($user->tenant);

        if ($user->isGerant() && !$user->boutique?->estActive()) {
            throw new ApiException(
                'Votre boutique est désactivée. Contactez le propriétaire.',
                'BOUTIQUE_DISABLED',
                403,
            );
        }
    }

    /**
     * Crée la période d'essai d'une nouvelle entreprise (si configurée).
     */
    public function createTrial(Tenant $tenant, ?int $createdBy = null): ?Subscription
    {
        $trialDays = (int) config('subscriptions.trial_days', 30);
        if ($trialDays <= 0) {
            return null;
        }

        $today = CarbonImmutable::today();

        return $tenant->subscriptions()->create([
            'plan' => 'Essai',
            'amount' => 0,
            'currency' => config('subscriptions.default_currency', 'XOF'),
            'starts_at' => $today,
            'ends_at' => $today->addDays($trialDays - 1),
            'notes' => 'Période d\'essai offerte à l\'inscription.',
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Abonnement offert à l'inscription : le forfait gratuit configuré (subscriptions.signup_plan),
     * ou la période d'essai s'il n'existe pas ou n'est plus proposé.
     */
    public function createSignupSubscription(Tenant $tenant): ?Subscription
    {
        $plan = SubscriptionPlan::where('name', config('subscriptions.signup_plan'))->where('is_active', true)->first();

        if (!$plan) {
            return $this->createTrial($tenant);
        }

        $today = CarbonImmutable::today()->toDateString();

        return $tenant->subscriptions()->create([
            'subscription_plan_id' => $plan->id,
            'plan' => $plan->name,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'starts_at' => $today,
            'ends_at' => $plan->endsAtFor($today)->toDateString(),
            'notes' => 'Forfait offert à l\'inscription.',
        ]);
    }

    /**
     * Attributs d'un abonnement à partir de la saisie validée : avec un forfait, la date de fin
     * est calculée et le nom, le montant et la devise sont recopiés (figés pour l'historique).
     * Sans forfait (essai, anciennes saisies), les dates saisies sont gardées et le montant inchangé.
     */
    public function attributesFor(array $data, ?Subscription $existing = null): array
    {
        $attributes = [
            'tenant_id' => $data['tenant_id'],
            'starts_at' => $data['starts_at'],
            'notes' => $data['notes'] ?? null,
        ];

        $planId = $data['subscription_plan_id'] ?? null;
        if (!$planId) {
            return $attributes + ['subscription_plan_id' => null, 'ends_at' => $data['ends_at']];
        }

        $plan = SubscriptionPlan::withTrashed()->findOrFail($planId);
        $attributes += [
            'subscription_plan_id' => $plan->id,
            'ends_at' => $plan->endsAtFor($data['starts_at'])->toDateString(),
        ];

        if ($existing?->subscription_plan_id !== $plan->id) {
            $attributes += ['plan' => $plan->name, 'amount' => $plan->price, 'currency' => $plan->currency];
        }

        return $attributes;
    }

    /**
     * Deux abonnements d'une même entreprise ne peuvent pas se chevaucher.
     */
    public function overlapping(int $tenantId, string $startsAt, string $endsAt, ?int $ignoreId = null): ?Subscription
    {
        return Subscription::where('tenant_id', $tenantId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('starts_at', '<=', $endsAt)
            ->whereDate('ends_at', '>=', $startsAt)
            ->first();
    }

    private function coverageEnd(Collection $subscriptions, Subscription $current): CarbonImmutable
    {
        $end = CarbonImmutable::parse($current->ends_at);

        foreach ($subscriptions->sortBy('starts_at') as $next) {
            $nextStart = CarbonImmutable::parse($next->starts_at);
            $nextEnd = CarbonImmutable::parse($next->ends_at);

            // Période suivante qui démarre au plus tard le lendemain de la fin courante
            if ($nextStart->lte($end->addDay()) && $nextEnd->gt($end)) {
                $end = $nextEnd;
            }
        }

        return $end;
    }
}
