<?php

namespace Tests\Feature\Concerns;

use App\Enums\RoleName;
use App\Models\Boutique;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Http;

trait BuildsTenants
{
    protected function setUpRoles(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value], ['label' => $role->label()]);
        }
    }

    /**
     * Compte propriétaire couvert par un abonnement en cours, sur un forfait autorisant
     * `$maxGerants` gérants par boutique (null = illimité).
     */
    protected function tenant(array $attributes = [], ?int $maxGerants = 3): Tenant
    {
        $tenant = Tenant::create($attributes + [
            'name' => 'Commerce ' . uniqid(),
            'code_website' => 'code-' . uniqid(),
        ])->fresh();

        $plan = SubscriptionPlan::create([
            'name' => 'Forfait ' . uniqid(),
            'duration_months' => 1,
            'price' => 5000,
            'currency' => 'XOF',
            'max_gerants_par_boutique' => $maxGerants,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id,
            'plan' => 'Mensuel',
            'amount' => 5000,
            'currency' => 'XOF',
            'starts_at' => today()->subDays(10),
            'ends_at' => today()->addDays(20),
        ]);

        return $tenant;
    }

    /** Propriétaire du compte. */
    protected function manager(Tenant $tenant, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['tenant_id' => $tenant->id]);
    }

    protected function boutique(Tenant $tenant, array $attributes = []): Boutique
    {
        return Boutique::create($attributes + [
            'tenant_id' => $tenant->id,
            'nom' => 'Boutique ' . uniqid(),
            'slug' => 'boutique-' . uniqid(),
        ])->fresh();
    }

    protected function gerant(Boutique $boutique, array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role_id' => Role::where('name', RoleName::Gerant->value)->value('id'),
            'tenant_id' => $boutique->tenant_id,
            'boutique_id' => $boutique->id,
        ]);
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function tokenFor(User $user): string
    {
        return app(TokenService::class)->issue($user->fresh())['access_token'];
    }

    protected function asUser(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($user));
    }

    /** WAHA simulé : les messages envoyés sont enregistrés par Http::fake. */
    protected function fakeWaha(): void
    {
        config([
            'services.waha.base_url' => 'https://waha.test',
            'services.waha.api_key' => 'test-key',
        ]);

        Http::fake([
            'waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => true]),
            'waha.test/*' => Http::response(['id' => 'msg'], 201),
        ]);
    }

    /** Dernier code OTP envoyé sur WhatsApp. */
    protected function lastOtp(): string
    {
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/sendText'))->last();
        $this->assertNotNull($sent, 'Aucun code envoyé sur WhatsApp.');
        preg_match('/\*(\d{6})\*/', $sent[0]['text'], $matches);

        return $matches[1];
    }
}
