<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\StatutBoutique;
use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\GerantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class GerantTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();
        $this->fakeWaha();
    }

    private function nouveauGerant(int $boutiqueId, string $phone): array
    {
        return [
            'boutique_id' => $boutiqueId,
            'first_name' => 'Awa',
            'last_name' => 'Diop',
            'phone_one' => $phone,
            'password' => 'Provisoire1',
        ];
    }

    public function test_limit_is_per_boutique(): void
    {
        $tenant = $this->tenant(maxGerants: 1);
        $owner = $this->manager($tenant);
        $first = $this->boutique($tenant);
        $second = $this->boutique($tenant);

        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($first->id, '761110001'))
            ->assertCreated()
            ->assertJsonPath('payload.boutique.id', $first->id)
            ->assertJsonPath('payload.must_change_password', true);

        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($first->id, '761110002'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'GERANTS_LIMIT_REACHED')
            ->assertJsonPath('payload.places', ['max' => 1, 'utilises' => 1, 'restantes' => 0]);

        // Une autre boutique du même propriétaire a sa propre limite
        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($second->id, '761110002'))
            ->assertCreated();

        $gerant = User::where('phone_one', '761110001')->first();
        $this->assertTrue($gerant->isGerant());
        $this->assertSame($tenant->id, $gerant->tenant_id);
    }

    public function test_plan_limits_three_ten_and_unlimited(): void
    {
        foreach ([3 => 3, 10 => 10] as $max => $expected) {
            $boutique = $this->boutique($this->tenant(maxGerants: $max));
            $this->assertSame(
                ['max' => $expected, 'utilises' => 0, 'restantes' => $expected],
                app(GerantService::class)->places($boutique),
            );
        }

        $boutique = $this->boutique($this->tenant(maxGerants: null));
        foreach (range(1, 12) as $i) {
            $this->gerant($boutique);
        }
        $this->assertSame(['max' => null, 'utilises' => 12, 'restantes' => null], app(GerantService::class)->places($boutique));
    }

    public function test_trial_without_plan_uses_configured_limit(): void
    {
        config(['subscriptions.trial_max_gerants_par_boutique' => 1]);
        $tenant = $this->tenant();
        Subscription::where('tenant_id', $tenant->id)->update(['subscription_plan_id' => null]);
        $boutique = $this->boutique($tenant);

        $this->assertSame(1, app(GerantService::class)->places($boutique)['max']);
    }

    public function test_downgrade_keeps_existing_gerants_but_blocks_new_ones(): void
    {
        $tenant = $this->tenant(maxGerants: 3);
        $owner = $this->manager($tenant);
        $boutique = $this->boutique($tenant);
        $gerants = collect(range(1, 3))->map(fn () => $this->gerant($boutique));

        SubscriptionPlan::query()->update(['max_gerants_par_boutique' => 1]);

        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($boutique->id, '761110009'))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'GERANTS_LIMIT_REACHED')
            ->assertJsonPath('payload.places.utilises', 3);

        // Rien n'est supprimé ni désactivé : les gérants en place continuent de travailler
        $this->assertSame(3, $boutique->gerants()->where('status', true)->count());
        $this->asUser($gerants->first())->getJson('/api/manager/dashboard')->assertOk();
    }

    public function test_deleting_a_gerant_frees_a_place(): void
    {
        $tenant = $this->tenant(maxGerants: 1);
        $owner = $this->manager($tenant);
        $boutique = $this->boutique($tenant);
        $gerant = $this->gerant($boutique);

        $this->asUser($owner)->deleteJson("/api/manager/gerants/{$gerant->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $gerant->id]);

        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($boutique->id, '761110003'))
            ->assertCreated();
    }

    public function test_disabled_gerant_still_holds_a_place(): void
    {
        $tenant = $this->tenant(maxGerants: 1);
        $owner = $this->manager($tenant);
        $boutique = $this->boutique($tenant);
        $gerant = $this->gerant($boutique);

        $this->asUser($owner)->putJson("/api/manager/gerants/{$gerant->id}/toggle-status")
            ->assertOk()->assertJsonPath('payload.status', false);

        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($boutique->id, '761110004'))
            ->assertStatus(422)->assertJsonPath('error_code', 'GERANTS_LIMIT_REACHED');
    }

    public function test_transfer_to_another_boutique_of_the_same_owner(): void
    {
        $tenant = $this->tenant(maxGerants: 1);
        $owner = $this->manager($tenant);
        $from = $this->boutique($tenant);
        $to = $this->boutique($tenant);
        $full = $this->boutique($tenant);
        $this->gerant($full);
        $gerant = $this->gerant($from);

        $this->asUser($owner)->putJson("/api/manager/gerants/{$gerant->id}/transfer", ['boutique_id' => $full->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'GERANTS_LIMIT_REACHED');

        $this->asUser($owner)->putJson("/api/manager/gerants/{$gerant->id}/transfer", ['boutique_id' => $from->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'TRANSFER_SAME_BOUTIQUE');

        $this->asUser($owner)->putJson("/api/manager/gerants/{$gerant->id}/transfer", ['boutique_id' => $to->id])
            ->assertOk()
            ->assertJsonPath('payload.boutique.id', $to->id);

        $this->assertSame($to->id, $gerant->fresh()->boutique_id);
    }

    public function test_transfer_to_another_owner_is_refused(): void
    {
        $tenant = $this->tenant();
        $owner = $this->manager($tenant);
        $gerant = $this->gerant($this->boutique($tenant));
        $other = $this->boutique($this->tenant());

        // Boutique d'un autre propriétaire : introuvable pour lui
        $this->asUser($owner)->putJson("/api/manager/gerants/{$gerant->id}/transfer", ['boutique_id' => $other->id])
            ->assertNotFound();

        // Et refusé par le service lui-même
        try {
            app(GerantService::class)->transferer($gerant, $other);
            $this->fail('Transfert vers un autre propriétaire accepté.');
        } catch (ApiException $e) {
            $this->assertSame('TRANSFER_OTHER_OWNER', $e->errorCode);
        }

        $this->assertNotSame($other->id, $gerant->fresh()->boutique_id);
    }

    public function test_owner_only_manages_own_gerants(): void
    {
        $owner = $this->manager($this->tenant());
        $foreign = $this->gerant($this->boutique($this->tenant()));
        $foreignBoutique = $foreign->boutique;

        $this->asUser($owner)->getJson('/api/manager/gerants')->assertOk()->assertJsonCount(0, 'payload');
        $this->asUser($owner)->deleteJson("/api/manager/gerants/{$foreign->id}")->assertNotFound();
        $this->asUser($owner)->postJson('/api/manager/gerants', $this->nouveauGerant($foreignBoutique->id, '761110005'))
            ->assertNotFound();
    }

    public function test_gerant_sees_only_his_boutique_and_cannot_manage_gerants(): void
    {
        $tenant = $this->tenant();
        $mine = $this->boutique($tenant, ['nom' => 'Ma boutique']);
        $this->boutique($tenant, ['nom' => 'Autre boutique']);
        $gerant = $this->gerant($mine);

        $this->asUser($gerant)->getJson('/api/manager/boutiques')
            ->assertOk()
            ->assertJsonCount(1, 'payload')
            ->assertJsonPath('payload.0.nom', 'Ma boutique')
            ->assertJsonPath('payload.0.gerants_places', null);

        $this->asUser($gerant)->getJson('/api/manager/dashboard')->assertOk();
        $this->asUser($gerant)->getJson('/api/manager/gerants')->assertForbidden();
        $this->asUser($gerant)->getJson('/api/manager/company')->assertForbidden();
    }

    public function test_gerant_first_login_requires_whatsapp_code(): void
    {
        $gerant = $this->gerant($this->boutique($this->tenant()), [
            'phone_one' => '761110006', 'password' => 'Provisoire1', 'must_change_password' => true,
        ]);

        $this->postJson('/api/auth/login', ['phone' => '761110006', 'password' => 'Provisoire1'])
            ->assertOk()
            ->assertJsonPath('payload.step', 'otp_required')
            ->assertJsonPath('payload.purpose', 'first_login');

        $this->assertTrue($gerant->isGerant());
    }

    public function test_gerant_of_a_disabled_boutique_is_blocked(): void
    {
        $boutique = $this->boutique($this->tenant());
        $gerant = $this->gerant($boutique, ['phone_one' => '761110007']);
        $token = $this->tokenFor($gerant);
        $boutique->update(['statut' => StatutBoutique::Suspendu]);

        $this->postJson('/api/auth/login', ['phone' => '761110007', 'password' => 'Secret2026'])
            ->assertStatus(403)->assertJsonPath('error_code', 'BOUTIQUE_DISABLED');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/manager/dashboard')
            ->assertStatus(403)->assertJsonPath('error_code', 'BOUTIQUE_DISABLED');
    }

    public function test_expired_subscription_also_blocks_gerants(): void
    {
        $tenant = $this->tenant();
        $gerant = $this->gerant($this->boutique($tenant));
        Subscription::where('tenant_id', $tenant->id)->update(['starts_at' => today()->subDays(40), 'ends_at' => today()->subDay()]);

        $this->asUser($gerant)->getJson('/api/manager/dashboard')
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_admin_creating_a_gerant_respects_the_limit(): void
    {
        $tenant = $this->tenant(maxGerants: 1);
        $boutique = $this->boutique($tenant);
        $this->gerant($boutique);
        $roleId = Role::where('name', RoleName::Gerant->value)->value('id');

        $this->asUser($this->admin())->postJson('/api/users/add', [
            'first_name' => 'Fatou', 'last_name' => 'Sall', 'phone_one' => '761110008',
            'password' => 'Provisoire1', 'role_id' => $roleId, 'boutique_id' => $boutique->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'GERANTS_LIMIT_REACHED');
    }
}
