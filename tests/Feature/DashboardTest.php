<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $owner;
    private User $gerant;
    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();

        $tenant = $this->tenant();
        $this->owner = $this->manager($tenant);
        $this->boutique = $this->boutique($tenant);
        $this->gerant = $this->gerant($this->boutique);
    }

    private function dette(int $clientId, int $montant, string $date): int
    {
        return $this->asUser($this->gerant)->postJson('/api/manager/dettes', [
            'client_id' => $clientId, 'montant' => $montant, 'date_creation' => $date,
        ])->assertCreated()->json('payload.id');
    }

    public function test_activite_sur_une_periode(): void
    {
        $client = $this->asUser($this->gerant)
            ->postJson('/api/manager/clients', ['nom' => 'Awa Fall', 'telephone' => '77 555 44 33'])
            ->assertCreated()->json('payload.id');

        $this->dette($client, 10000, today()->subDays(20)->toDateString());
        $this->dette($client, 4000, today()->subDays(3)->toDateString());
        $annulee = $this->dette($client, 2500, today()->subDays(2)->toDateString());
        // Annulation réservée au propriétaire
        $this->asUser($this->owner)->postJson("/api/manager/dettes/{$annulee}/annuler")->assertOk();

        // Un versement réparti sur deux dettes : un seul versement de 12 000
        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$client}/paiements", [
            'montant' => 12000, 'methode' => 'especes',
        ])->assertCreated();

        $du = today()->subDays(7)->toDateString();
        $au = today()->toDateString();

        $this->asUser($this->gerant)->getJson("/api/manager/dashboard?du={$du}&au={$au}")
            ->assertOk()
            ->assertJsonPath('payload.periode.du', $du)
            ->assertJsonPath('payload.periode.au', $au)
            ->assertJsonPath('payload.periode.encaisse', 12000)
            ->assertJsonPath('payload.periode.versements_count', 1)
            // La dette d'il y a 20 jours et la dette annulée ne comptent pas
            ->assertJsonPath('payload.periode.dettes_count', 1)
            ->assertJsonPath('payload.periode.dettes_montant', 4000);

        // Un seul jour, sans « au »
        $this->asUser($this->gerant)->getJson('/api/manager/dashboard?du='.today()->subDays(20)->toDateString())
            ->assertJsonPath('payload.periode.dettes_count', 1)
            ->assertJsonPath('payload.periode.encaisse', 0);
    }

    public function test_sans_periode_la_reponse_ne_change_pas(): void
    {
        $this->asUser($this->gerant)->getJson('/api/manager/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('payload.periode');
    }

    public function test_periode_invalide_refusee(): void
    {
        $this->asUser($this->gerant)
            ->getJson('/api/manager/dashboard?du=2026-10-10&au=2026-10-01')
            ->assertUnprocessable();
    }
}
