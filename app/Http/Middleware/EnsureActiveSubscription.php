<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Coupe l'accès d'un propriétaire ou de ses gérants quand son compte est désactivé ou n'est plus
 * couvert par un abonnement (ou, pour un gérant, quand sa boutique est désactivée),
 * y compris pour une session ouverte avant l'échéance.
 */
class EnsureActiveSubscription
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user?->isBoutiquier()) {
            $this->subscriptions->ensureBoutiquierAccess($user);
        }

        return $next($request);
    }
}
