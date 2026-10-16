<?php

namespace Tests\Feature;

use App\Enums\StatutDette;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();
    }

    public function test_new_tenant_receives_a_trial(): void
    {
        $id = $this->asUser($this->admin())
            ->postJson('/api/tenants/add', ['name' => 'Sow Électricité', 'code_website' => 'sow', 'primary_color' => '#dc2626'])
            ->assertCreated()
            ->assertJsonPath('payload.primary_color', '#DC2626')
            ->assertJsonPath('payload.secondary_color', '#17202A')
            ->json('payload.id');

        $this->asUser($this->admin())
            ->getJson("/api/subscriptions/tenant/{$id}")
            ->assertJsonPath('payload.status.state', 'active')
            ->assertJsonPath('payload.status.plan', 'Essai')
            ->assertJsonPath('payload.status.ends_at', today()->addDays(13)->toDateString());
    }

    public function test_overlaps_are_refused_and_renewals_are_chained(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin();
        $current = Subscription::where('tenant_id', $tenant->id)->first();
        $plan = $this->plan(3);

        $this->asUser($admin)->postJson('/api/subscriptions/add', [
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $plan->id,
            'starts_at' => $current->ends_at->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error_code', 'SUBSCRIPTION_OVERLAP');

        $start = $current->ends_at->addDay();
        $this->asUser($admin)->postJson('/api/subscriptions/add', [
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $plan->id,
            'starts_at' => $start->toDateString(),
        ])->assertCreated();

        $status = app(SubscriptionService::class)->statusForTenant($tenant->fresh());
        $this->assertSame($start->addMonthsNoOverflow(3)->subDay()->toDateString(), $status['ends_at']);
    }

    public function test_default_plans_limit_gerants_per_boutique(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(
            ['Gratuit' => 1, 'Boutique' => 3, 'Boutique Pro' => 10, 'Annuel' => null],
            SubscriptionPlan::ordered()->pluck('max_gerants_par_boutique', 'name')->all(),
        );
    }

    public function test_subscription_copies_plan_and_computes_end_date(): void
    {
        $tenant = $this->tenant();
        $plan = $this->plan(6);
        Subscription::where('tenant_id', $tenant->id)->delete();

        $this->asUser($this->admin())->postJson('/api/subscriptions/add', [
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $plan->id,
            'starts_at' => '2026-01-15', 'ends_at' => '2030-01-01', 'amount' => 1,
        ])->assertCreated()
            ->assertJsonPath('payload.plan', $plan->name)
            ->assertJsonPath('payload.amount', 12000)
            ->assertJsonPath('payload.subscription_plan_id', $plan->id)
            ->assertJsonPath('payload.ends_at', '2026-07-14');
    }

    public function test_plan_is_required_and_must_be_active(): void
    {
        $tenant = $this->tenant();
        $admin = $this->admin();
        $plan = $this->plan(3);
        $plan->update(['is_active' => false]);

        $this->asUser($admin)->postJson('/api/subscriptions/add', [
            'tenant_id' => $tenant->id, 'starts_at' => today()->addMonths(2)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('subscription_plan_id');

        $this->asUser($admin)->postJson('/api/subscriptions/add', [
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $plan->id,
            'starts_at' => today()->addMonths(2)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('subscription_plan_id');
    }

    public function test_subscription_without_plan_keeps_manual_dates(): void
    {
        $tenant = $this->tenant();
        $trial = Subscription::where('tenant_id', $tenant->id)->first();
        $trial->update(['subscription_plan_id' => null]);

        $this->asUser($this->admin())->putJson("/api/subscriptions/update/{$trial->id}", [
            'tenant_id' => $tenant->id, 'starts_at' => $trial->starts_at->toDateString(),
            'ends_at' => today()->addDays(40)->toDateString(),
        ])->assertOk()
            ->assertJsonPath('payload.plan', 'Mensuel')
            ->assertJsonPath('payload.amount', 5000)
            ->assertJsonPath('payload.ends_at', today()->addDays(40)->toDateString());

        // Formulaire web : subscription_plan_id envoyé explicitement à null
        $this->asUser($this->admin())->putJson("/api/subscriptions/update/{$trial->id}", [
            'tenant_id' => $tenant->id, 'subscription_plan_id' => null, 'starts_at' => $trial->starts_at->toDateString(),
            'ends_at' => today()->addDays(50)->toDateString(), 'notes' => null,
        ])->assertOk()
            ->assertJsonPath('payload.ends_at', today()->addDays(50)->toDateString());
    }

    public function test_plan_crud(): void
    {
        $admin = $this->admin();

        $id = $this->asUser($admin)->postJson('/api/subscription-plans/add', [
            'name' => 'Forfait 2 ans', 'duration_months' => 24, 'price' => 45000,
        ])->assertCreated()
            ->assertJsonPath('payload.currency', 'XOF')
            ->assertJsonPath('payload.is_active', true)
            ->json('payload.id');

        $this->asUser($admin)->putJson("/api/subscription-plans/update/{$id}", ['name' => 'Forfait 24 mois', 'price' => 40000])
            ->assertStatus(422)->assertJsonValidationErrors('price');

        $this->asUser($admin)->putJson("/api/subscription-plans/update/{$id}", ['name' => 'Forfait 24 mois'])
            ->assertOk()
            ->assertJsonPath('payload.name', 'Forfait 24 mois')
            ->assertJsonPath('payload.price', 45000);

        $this->asUser($admin)->putJson("/api/subscription-plans/toggle-status/{$id}")
            ->assertOk()->assertJsonPath('payload.is_active', false);

        $this->asUser($admin)->putJson("/api/subscription-plans/update/{$id}", ['name' => 'Forfait 24 mois', 'max_gerants_par_boutique' => 5])
            ->assertOk()->assertJsonPath('payload.max_gerants_par_boutique', 5);

        $this->asUser($admin)->getJson('/api/subscription-plans/list')
            ->assertOk()->assertJsonCount(1, 'payload');

        $this->asUser($admin)->deleteJson("/api/subscription-plans/delete/{$id}")->assertOk();
        $this->assertSoftDeleted('subscription_plans', ['id' => $id]);
    }

    public function test_used_plan_cannot_be_deleted(): void
    {
        $tenant = $this->tenant();
        $plan = $this->plan(3);
        Subscription::create([
            'tenant_id' => $tenant->id, 'subscription_plan_id' => $plan->id, 'plan' => $plan->name,
            'amount' => $plan->price, 'starts_at' => today()->addMonths(2), 'ends_at' => today()->addMonths(5),
        ]);

        $this->asUser($this->admin())->deleteJson("/api/subscription-plans/delete/{$plan->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'PLAN_IN_USE');
    }

    public function test_manager_cannot_manage_plans(): void
    {
        $this->asUser($this->manager($this->tenant()))
            ->postJson('/api/subscription-plans/add', ['name' => 'X', 'duration_months' => 1, 'price' => 1])
            ->assertStatus(403);
    }

    public function test_public_offers_list_active_plans(): void
    {
        $this->plan(3);
        $this->plan(12);
        $this->plan(6)->update(['is_active' => false]);
        config(['subscriptions.contact.phone' => '770000000']);

        $this->getJson('/api/subscription-offers')
            ->assertOk()
            ->assertJsonCount(2, 'payload.plans')
            ->assertJsonPath('payload.plans.0.price', 6000)
            ->assertJsonPath('payload.plans.1.duration_months', 12)
            ->assertJsonPath('payload.contact.phone', '770000000');
    }

    public function test_expired_subscription_blocks_manager_everywhere(): void
    {
        $tenant = $this->tenant();
        $manager = $this->manager($tenant);
        $token = $this->tokenFor($manager);
        $end = today()->subDay();
        Subscription::where('tenant_id', $tenant->id)->update(['starts_at' => today()->subDays(40), 'ends_at' => $end]);
        $expected = "Votre abonnement a expiré le {$end->format('d/m/Y')}. Contactez l'administrateur pour le renouveler.";

        $this->postJson('/api/auth/login', ['phone' => $manager->phone_one, 'password' => 'Secret2026'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED')
            ->assertJsonPath('message', $expected);

        foreach ([['getJson', '/api/auth/me'], ['postJson', '/api/auth/change-password'], ['getJson', '/api/manager/dashboard']] as [$method, $url]) {
            $this->withHeader('Authorization', "Bearer {$token}")->{$method}($url)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        }
    }

    private function plan(int $months): SubscriptionPlan
    {
        return SubscriptionPlan::firstOrCreate(['duration_months' => $months, 'name' => "Forfait {$months} mois"], [
            'price' => [3 => 6000, 6 => 12000, 12 => 25000][$months],
            'currency' => 'XOF',
            'max_gerants_par_boutique' => 3,
            'position' => $months,
        ]);
    }

    public function test_states(): void
    {
        $service = app(SubscriptionService::class);
        $tenant = $this->tenant();
        $subscription = Subscription::where('tenant_id', $tenant->id)->first();

        $this->assertSame('active', $service->statusForTenant($tenant->fresh())['state']);

        $subscription->update(['ends_at' => today()->addDays(3)]);
        $this->assertSame('expiring', $service->statusForTenant($tenant->fresh())['state']);

        $subscription->update(['ends_at' => today()->subDay()]);
        $this->assertSame('expired', $service->statusForTenant($tenant->fresh())['state']);

        $subscription->delete();
        $this->assertSame('none', $service->statusForTenant($tenant->fresh())['state']);
    }

    public function test_disabled_tenant_blocks_manager(): void
    {
        $tenant = $this->tenant();
        $manager = $this->manager($tenant);
        $tenant->update(['state' => false]);

        $this->postJson('/api/auth/login', ['phone' => $manager->phone_one, 'password' => 'Secret2026'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'TENANT_DISABLED');
    }

    public function test_manager_cannot_manage_subscriptions(): void
    {
        $this->asUser($this->manager($this->tenant()))
            ->getJson('/api/subscriptions/overview')
            ->assertStatus(403);
    }

    public function test_admin_dashboard(): void
    {
        $this->manager($this->tenant());
        $expired = $this->tenant();
        Subscription::where('tenant_id', $expired->id)->update(['starts_at' => today()->subDays(40), 'ends_at' => today()->subDay()]);

        $this->asUser($this->admin())
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('payload.tenants.total', 2)
            ->assertJsonPath('payload.proprietaires', 1)
            ->assertJsonPath('payload.sans_boutique', 2)
            ->assertJsonPath('payload.subscriptions.active', 1)
            ->assertJsonPath('payload.subscriptions.expired', 1)
            ->assertJsonPath('payload.attention.0.tenant_id', $expired->id);
    }

    public function test_admin_dashboard_stats_per_tenant(): void
    {
        $tenant = $this->tenant(['name' => 'Alpha']);
        $boutique = $this->boutique($tenant);
        $client = Client::create(['boutique_id' => $boutique->id, 'nom' => 'Awa Diop', 'telephone' => '765554433']);
        $dette = fn (float $montant, StatutDette $statut, float $restant) => Dette::create([
            'boutique_id' => $boutique->id, 'client_id' => $client->id, 'reference' => 'D-' . uniqid(),
            'montant' => $montant, 'montant_paye' => $montant - $restant, 'montant_restant' => $restant,
            'date_creation' => today(), 'statut' => $statut,
        ]);
        $dette(10000, StatutDette::EnRetard, 6000);
        $dette(5000, StatutDette::EnAttente, 5000);
        $dette(3000, StatutDette::Payee, 0);
        $dette(99999, StatutDette::Annulee, 99999);
        $dette(88888, StatutDette::EnRetard, 88888)->delete();
        Paiement::create([
            'dette_id' => Dette::first()->id, 'boutique_id' => $boutique->id,
            'montant' => 4000, 'date_paiement' => today(), 'methode' => 'wave',
        ]);
        $this->tenant(['name' => 'Beta']);

        $this->asUser($this->admin())
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('payload.boutiques', 1)
            ->assertJsonPath('payload.sans_boutique', 1)
            ->assertJsonCount(2, 'payload.tenant_stats')
            ->assertJsonPath('payload.tenant_stats.0.tenant_name', 'Alpha')
            ->assertJsonPath('payload.tenant_stats.0.boutiques', 1)
            ->assertJsonPath('payload.tenant_stats.0.a_recuperer', 11000)
            ->assertJsonPath('payload.tenant_stats.0.encaisse', 4000)
            ->assertJsonPath('payload.tenant_stats.1.tenant_name', 'Beta')
            ->assertJsonPath('payload.tenant_stats.1.a_recuperer', 0);
    }
}
