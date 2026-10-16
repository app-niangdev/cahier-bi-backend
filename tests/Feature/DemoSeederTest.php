<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Boutique;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_has_two_owners_each_with_a_boutique_and_a_gerant(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class); // idempotent

        $this->assertSame(2, Tenant::count());
        $this->assertSame(2, Boutique::count());

        foreach (Tenant::with('boutiques', 'users.role')->get() as $tenant) {
            $this->assertCount(1, $tenant->boutiques);
            $this->assertSame([RoleName::Proprietaire->value, RoleName::Gerant->value], $tenant->users->sortBy('id')->pluck('role.name')->values()->all());
            $this->assertSame($tenant->boutiques->first()->id, $tenant->users->first(fn (User $u) => $u->isGerant())->boutique_id);
            $this->assertTrue($tenant->subscriptions()->exists());
        }
    }
}
