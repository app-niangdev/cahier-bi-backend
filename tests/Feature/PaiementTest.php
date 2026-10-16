<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class PaiementTest extends TestCase
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

    private function client(string $nom = 'Modou Diop'): int
    {
        return $this->asUser($this->gerant)->postJson('/api/manager/clients', ['nom' => $nom, 'telephone' => '77 555 44 33'])
            ->assertCreated()
            ->assertJsonPath('payload.boutique_id', $this->boutique->id)
            ->json('payload.id');
    }

    private function dette(int $clientId, int $montant, string $date): int
    {
        return $this->asUser($this->gerant)->postJson('/api/manager/dettes', [
            'client_id' => $clientId, 'montant' => $montant, 'date_creation' => $date,
        ])->assertCreated()->json('payload.id');
    }

    private function payer(int $clientId, int $montant, string $methode = 'especes')
    {
        return $this->asUser($this->gerant)->postJson("/api/manager/clients/{$clientId}/paiements", [
            'montant' => $montant, 'methode' => $methode,
        ]);
    }

    public function test_modou_pays_by_installments_and_buys_again_before_finishing(): void
    {
        $modou = $this->client();
        $d1 = $this->dette($modou, 13000, today()->subDays(10)->toDateString());

        $this->payer($modou, 5000)->assertCreated()
            ->assertJsonPath('payload.repartition.0.montant_restant', 8000)
            ->assertJsonPath('payload.client.total_du', 8000);

        $this->payer($modou, 2500, 'wave')->assertCreated()
            ->assertJsonPath('payload.client.total_du', 5500);

        // Nouvel achat avant d'avoir tout payé : une nouvelle dette
        $d2 = $this->dette($modou, 4000, today()->toDateString());
        $this->asUser($this->gerant)->getJson("/api/manager/clients/{$modou}")
            ->assertJsonPath('payload.total_du', 9500)
            ->assertJsonCount(2, 'payload.dettes');

        // « Modou a payé 7 000 » : 5 500 soldent D-1, 1 500 vont sur D-2
        $this->payer($modou, 7000, 'orange_money')->assertCreated()
            ->assertJsonCount(2, 'payload.repartition')
            ->assertJsonPath('payload.repartition.0.dette_id', $d1)
            ->assertJsonPath('payload.repartition.0.montant', 5500)
            ->assertJsonPath('payload.repartition.0.statut', 'payee')
            ->assertJsonPath('payload.repartition.1.dette_id', $d2)
            ->assertJsonPath('payload.repartition.1.montant', 1500)
            ->assertJsonPath('payload.repartition.1.montant_restant', 2500)
            ->assertJsonPath('payload.client.total_du', 2500);

        $this->assertEquals(13000, Dette::find($d1)->montant_paye);
        $this->assertSame(2, Paiement::whereNotNull('versement')->distinct()->count('dette_id'));

        // Historique : 3 versements, le dernier réparti sur 2 dettes
        $this->asUser($this->owner)->getJson("/api/manager/clients/{$modou}/versements")
            ->assertOk()
            ->assertJsonCount(3, 'payload')
            ->assertJsonPath('payload.0.montant', 7000)
            ->assertJsonCount(2, 'payload.0.repartition');
    }

    public function test_payment_cannot_exceed_what_is_owed(): void
    {
        $modou = $this->client();
        $this->dette($modou, 13000, today()->toDateString());

        $this->payer($modou, 13001)->assertStatus(422)
            ->assertJsonPath('error_code', 'MONTANT_SUPERIEUR_AU_RESTE')
            ->assertJsonPath('payload.reste_du', 13000);

        $this->payer($modou, 13000)->assertCreated()->assertJsonPath('payload.client.total_du', 0);
        $this->payer($modou, 100)->assertStatus(422)->assertJsonPath('error_code', 'AUCUNE_DETTE');
    }

    public function test_payment_on_a_specific_debt(): void
    {
        $modou = $this->client();
        $d1 = $this->dette($modou, 13000, today()->subDays(5)->toDateString());
        $d2 = $this->dette($modou, 4000, today()->toDateString());

        // Imputé sur D-2 seulement, même si D-1 est plus ancienne
        $this->asUser($this->gerant)->postJson("/api/manager/dettes/{$d2}/paiements", ['montant' => 4000, 'methode' => 'wave'])
            ->assertCreated()
            ->assertJsonCount(1, 'payload.repartition')
            ->assertJsonPath('payload.repartition.0.statut', 'payee');

        $this->assertEquals(13000, Dette::find($d1)->montant_restant);

        $this->asUser($this->gerant)->postJson("/api/manager/dettes/{$d2}/paiements", ['montant' => 1, 'methode' => 'wave'])
            ->assertStatus(422)->assertJsonPath('error_code', 'DETTE_CLOSE');
    }

    public function test_owner_cancels_a_versement_and_debts_are_restored(): void
    {
        $modou = $this->client();
        $d1 = $this->dette($modou, 5000, today()->subDays(3)->toDateString());
        $d2 = $this->dette($modou, 4000, today()->toDateString());
        $versement = $this->payer($modou, 7000)->json('payload.versement');

        $this->asUser($this->gerant)->deleteJson("/api/manager/versements/{$versement}")->assertForbidden();

        $this->asUser($this->owner)->deleteJson("/api/manager/versements/{$versement}")
            ->assertOk()
            ->assertJsonPath('payload.montant', 7000);

        $this->assertEquals(5000, Dette::find($d1)->montant_restant);
        $this->assertSame('en_attente', Dette::find($d1)->statut->value);
        $this->assertEquals(4000, Dette::find($d2)->montant_restant);
        $this->assertSame(0, Paiement::count());
    }

    public function test_debt_amount_rules(): void
    {
        $modou = $this->client();
        $d1 = $this->dette($modou, 10000, today()->toDateString());
        $this->payer($modou, 6000);

        $this->asUser($this->gerant)->putJson("/api/manager/dettes/{$d1}", ['montant' => 12000])
            ->assertStatus(403);
        $this->asUser($this->owner)->putJson("/api/manager/dettes/{$d1}", ['montant' => 5000])
            ->assertStatus(422)->assertJsonPath('error_code', 'MONTANT_INFERIEUR_AU_PAYE');
        $this->asUser($this->owner)->putJson("/api/manager/dettes/{$d1}", ['montant' => 12000])
            ->assertOk()->assertJsonPath('payload.montant_restant', 6000);

        // Une dette déjà payée en partie ne s'annule pas directement
        $this->asUser($this->owner)->postJson("/api/manager/dettes/{$d1}/annuler")
            ->assertStatus(422)->assertJsonPath('error_code', 'DETTE_HAS_PAYMENTS');
    }

    public function test_references_are_numbered_per_boutique_and_past_due_is_late(): void
    {
        $modou = $this->client();

        $this->asUser($this->gerant)->postJson('/api/manager/dettes', [
            'client_id' => $modou, 'montant' => 1000, 'date_echeance' => today()->subDay()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('payload.reference', 'D-0001')
            ->assertJsonPath('payload.statut', 'en_retard');

        $this->asUser($this->gerant)->postJson('/api/manager/dettes', ['client_id' => $modou, 'montant' => 1000])
            ->assertJsonPath('payload.reference', 'D-0002')
            ->assertJsonPath('payload.statut', 'en_attente');
    }

    public function test_clients_and_debts_of_another_boutique_are_invisible(): void
    {
        $other = $this->boutique($this->owner->tenant);
        $client = Client::create(['boutique_id' => $other->id, 'nom' => 'Autre']);
        $dette = Dette::create([
            'boutique_id' => $other->id, 'client_id' => $client->id, 'reference' => 'D-0001',
            'montant' => 1000, 'montant_restant' => 1000, 'date_creation' => today(),
        ]);

        // Le gérant ne voit que sa boutique
        $this->asUser($this->gerant)->getJson("/api/manager/clients/{$client->id}")->assertNotFound();
        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$client->id}/paiements", ['montant' => 100, 'methode' => 'especes'])
            ->assertNotFound();
        $this->asUser($this->gerant)->postJson('/api/manager/dettes', ['client_id' => $client->id, 'montant' => 500])
            ->assertNotFound();

        // Le propriétaire voit toutes ses boutiques, pas celles d'un autre
        $this->asUser($this->owner)->getJson("/api/manager/dettes/{$dette->id}")->assertOk();
        $stranger = $this->manager($this->tenant());
        $this->asUser($stranger)->getJson("/api/manager/dettes/{$dette->id}")->assertNotFound();
    }

    public function test_client_with_debt_cannot_be_deleted(): void
    {
        $modou = $this->client();
        $this->dette($modou, 1000, today()->toDateString());

        $this->asUser($this->gerant)->deleteJson("/api/manager/clients/{$modou}")
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_HAS_DEBT');

        $this->payer($modou, 1000);
        $this->asUser($this->gerant)->deleteJson("/api/manager/clients/{$modou}")->assertOk();
    }
}
