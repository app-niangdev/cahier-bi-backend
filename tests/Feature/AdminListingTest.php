<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

/** Listes de l'administrateur : éléments supprimés visibles sur demande, pour les restaurer. */
class AdminListingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();
        $this->fakeWaha();
    }

    public function test_deleted_tenants_are_listed_only_on_request(): void
    {
        $admin = $this->admin();
        $tenant = $this->tenant();
        $tenant->delete();

        $this->asUser($admin)->getJson('/api/tenants/list')
            ->assertOk()
            ->assertJsonCount(0, 'payload');

        $this->asUser($admin)->getJson('/api/tenants/list?with_trashed=1')
            ->assertOk()
            ->assertJsonPath('payload.0.id', $tenant->id)
            ->assertJsonPath('payload.0.deleted_at', fn ($value) => $value !== null);

        $this->asUser($admin)->getJson("/api/tenants/show/{$tenant->id}")->assertOk();
    }

    public function test_deleted_boutiques_and_users_are_listed_only_on_request(): void
    {
        $admin = $this->admin();
        $boutique = $this->boutique($this->tenant());
        $gerant = $this->gerant($boutique);
        $boutique->delete();
        $gerant->delete();

        $this->asUser($admin)->getJson('/api/boutiques/list')->assertJsonCount(0, 'payload');
        $this->asUser($admin)->getJson('/api/boutiques/list?with_trashed=1')
            ->assertJsonPath('payload.0.id', $boutique->id)
            ->assertJsonPath('payload.0.deleted_at', fn ($value) => $value !== null);

        $ids = collect($this->asUser($admin)->getJson('/api/users/list?with_trashed=1&per_page=50')->json('payload'));
        $this->assertNotNull($ids->firstWhere('id', $gerant->id)['deleted_at']);
        $this->assertNull(collect($this->asUser($admin)->getJson('/api/users/list?per_page=50')->json('payload'))->firstWhere('id', $gerant->id));
    }

    public function test_offers_expose_trial_days(): void
    {
        $this->getJson('/api/subscription-offers')->assertOk()->assertJsonPath('payload.trial_days', 14);
    }
}
