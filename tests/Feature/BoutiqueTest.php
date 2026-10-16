<?php

namespace Tests\Feature;

use App\Enums\StatutDette;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class BoutiqueTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();
    }

    public function test_admin_creates_a_boutique_for_an_owner(): void
    {
        $tenant = $this->tenant(maxGerants: 3);

        $id = $this->asUser($this->admin())->postJson('/api/boutiques/add', [
            'tenant_id' => $tenant->id,
            'nom' => 'Boutique Fall',
            'telephone' => '77 123 45 67',
            'quartier' => 'Médina',
            'ville' => 'Dakar',
        ])->assertCreated()
            ->assertJsonPath('payload.statut', 'actif')
            ->assertJsonPath('payload.telephone', '771234567')
            ->assertJsonPath('payload.tenant.id', $tenant->id)
            ->assertJsonPath('payload.gerants_places', ['max' => 3, 'utilises' => 0, 'restantes' => 3])
            ->json('payload.id');

        $this->assertStringStartsWith('boutique-fall-', Boutique::find($id)->slug);

        $this->asUser($this->admin())->putJson("/api/boutiques/update/{$id}", ['tenant_id' => $this->tenant()->id])
            ->assertStatus(422)->assertJsonValidationErrors('tenant_id');

        $this->asUser($this->admin())->putJson("/api/boutiques/update/{$id}", ['statut' => 'suspendu'])
            ->assertOk()->assertJsonPath('payload.statut', 'suspendu');

        $this->asUser($this->admin())->getJson("/api/boutiques/list?tenant_id={$tenant->id}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('payload.0.gerants_count', 0);
    }

    public function test_owner_cannot_create_a_boutique(): void
    {
        $tenant = $this->tenant();

        $this->asUser($this->manager($tenant))
            ->postJson('/api/boutiques/add', ['tenant_id' => $tenant->id, 'nom' => 'Ma boutique'])
            ->assertForbidden();

        $this->assertSame(0, Boutique::count());
    }

    public function test_owner_lists_his_boutiques_with_places(): void
    {
        $tenant = $this->tenant(maxGerants: 3);
        $boutique = $this->boutique($tenant, ['nom' => 'A']);
        $this->gerant($boutique);
        $this->boutique($this->tenant(), ['nom' => 'Pas à moi']);

        $this->asUser($this->manager($tenant))->getJson('/api/manager/boutiques')
            ->assertOk()
            ->assertJsonCount(1, 'payload')
            ->assertJsonPath('payload.0.gerants_places', ['max' => 3, 'utilises' => 1, 'restantes' => 2]);
    }

    public function test_debt_is_recalculated_from_payments(): void
    {
        $boutique = $this->boutique($this->tenant());
        $client = Client::create(['boutique_id' => $boutique->id, 'nom' => 'Awa']);
        $dette = Dette::create([
            'boutique_id' => $boutique->id, 'client_id' => $client->id, 'reference' => 'D-1',
            'montant' => 10000, 'montant_restant' => 10000, 'date_creation' => today(),
            'date_echeance' => today()->subDay(),
        ]);
        $payer = fn (int $montant) => Paiement::create([
            'dette_id' => $dette->id, 'boutique_id' => $boutique->id,
            'montant' => $montant, 'date_paiement' => today(), 'methode' => 'especes',
        ]);

        $payer(4000);
        $dette->recalculer();
        $this->assertSame(StatutDette::EnRetard, $dette->statut);
        $this->assertEquals(6000, $dette->montant_restant);
        $this->assertEquals(6000, $client->totalDu());

        $payer(6000);
        $dette->recalculer();
        $this->assertSame(StatutDette::Payee, $dette->statut);
        $this->assertEquals(0, $dette->montant_restant);
        $this->assertEquals(0, $boutique->totalARecuperer());
    }

    public function test_daily_command_updates_statuses_from_due_date(): void
    {
        $boutique = $this->boutique($this->tenant());
        $client = Client::create(['boutique_id' => $boutique->id, 'nom' => 'Awa']);
        $dette = fn (?string $echeance, StatutDette $statut = StatutDette::EnAttente) => Dette::create([
            'boutique_id' => $boutique->id, 'client_id' => $client->id, 'reference' => 'D-' . uniqid(),
            'montant' => 1000, 'montant_restant' => 1000, 'date_creation' => today(),
            'date_echeance' => $echeance, 'statut' => $statut,
        ]);

        $retard = $dette(today()->subDay()->toDateString());
        $aujourdhui = $dette(today()->toDateString());
        $plusTard = $dette(today()->addDay()->toDateString(), StatutDette::AAujourdhui);
        $sansEcheance = $dette(null);
        $payee = $dette(today()->subDays(5)->toDateString(), StatutDette::Payee);
        $annulee = $dette(today()->subDays(5)->toDateString(), StatutDette::Annulee);

        Artisan::call('dettes:actualiser-statuts');

        $this->assertSame(StatutDette::EnRetard, $retard->fresh()->statut);
        $this->assertSame(StatutDette::AAujourdhui, $aujourdhui->fresh()->statut);
        $this->assertSame(StatutDette::EnAttente, $plusTard->fresh()->statut);
        $this->assertSame(StatutDette::EnAttente, $sansEcheance->fresh()->statut);
        $this->assertSame(StatutDette::Payee, $payee->fresh()->statut);
        $this->assertSame(StatutDette::Annulee, $annulee->fresh()->statut);
    }
}
