<?php

namespace App\Http\Controllers\Manager;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyRequest;
use App\Interfaces\TenantServiceInterface;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compte du propriétaire : informations, charte graphique et abonnement.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly TenantServiceInterface $tenants,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($request->user()->tenant));
    }

    /** POST (et non PUT) : formulaire multipart avec logo. */
    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        $tenant = $this->tenants->updateProfile($request->user()->tenant, $request->validated());

        return ApiResponse::success($this->present($tenant), 'Informations mises à jour.');
    }

    public function subscription(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return ApiResponse::success([
            'status' => $this->subscriptions->statusForTenant($tenant),
            'warning_days' => $this->subscriptions->warningDays(),
            'max_gerants_par_boutique' => $this->subscriptions->maxGerantsParBoutique($tenant),
        ]);
    }

    private function present(Tenant $tenant): array
    {
        return $tenant->only([
            'id', 'name', 'short_name', 'code_website', 'slogan', 'description', 'address', 'email', 'trade', 'ninea', 'rccm',
            'phone_call', 'phone_whatsapp', 'phone_other', 'logo_url', 'snap', 'instagram', 'facebook', 'tiktok',
            'primary_color', 'secondary_color', 'accent_color',
        ]);
    }
}
