<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Rappel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsTenants;
use Tests\TestCase;

class RappelTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private User $gerant;
    private Boutique $boutique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRoles();

        $tenant = $this->tenant();
        $this->manager($tenant);
        $this->boutique = $this->boutique($tenant, ['nom' => 'Alimentation Fall']);
        $this->gerant = $this->gerant($this->boutique);
    }

    private function client(array $data = []): int
    {
        return $this->asUser($this->gerant)
            ->postJson('/api/manager/clients', $data + ['nom' => 'Modou Diop', 'telephone' => '77 555 44 33'])
            ->assertCreated()->json('payload.id');
    }

    private function dette(int $clientId, int $montant): void
    {
        $this->asUser($this->gerant)->postJson('/api/manager/dettes', [
            'client_id' => $clientId, 'montant' => $montant, 'date_creation' => today()->subDays(5)->toDateString(),
        ])->assertCreated();
    }

    public function test_rappel_poli_envoye_sur_whatsapp_pour_le_total_du(): void
    {
        $this->fakeWaha();
        $modou = $this->client();
        $this->dette($modou, 3000);
        $this->dette($modou, 1700);

        $this->asUser($this->gerant)->getJson("/api/manager/clients/{$modou}/rappel")
            ->assertOk()
            ->assertJsonPath('payload.total_du', 4700)
            ->assertJsonPath('payload.telephone', '775554433');

        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$modou}/rappel")
            ->assertCreated()
            ->assertJsonPath('payload.statut', 'envoye');

        // Un seul message, poli, pour le cumul des deux dettes
        Http::assertSentCount(2); // vérification du numéro + envoi
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText')
            && $request['chatId'] === '221775554433@c.us'
            && str_contains($request['text'], 'Petit rappel amical')
            && str_contains($request['text'], '4 700 FCFA')
            && str_contains($request['text'], 'Alimentation Fall'));

        $rappel = Rappel::sole();
        $this->assertSame('poli', $rappel->ton->value);
        $this->assertSame('whatsapp', $rappel->canal->value);
        $this->assertNotNull($rappel->envoye_le);
    }

    public function test_le_numero_whatsapp_prime_sur_le_telephone(): void
    {
        $this->fakeWaha();
        $client = $this->client(['whatsapp' => '78 111 22 33']);
        $this->dette($client, 2000);

        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$client}/rappel")->assertCreated();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText')
            && $request['chatId'] === '221781112233@c.us');
    }

    public function test_client_sans_dette_ou_sans_numero(): void
    {
        $this->fakeWaha();
        $aJour = $this->client();
        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$aJour}/rappel")
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_SANS_DETTE');

        $sansNumero = $this->client(['nom' => 'Awa', 'telephone' => null]);
        $this->dette($sansNumero, 1000);
        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$sansNumero}/rappel")
            ->assertStatus(422)->assertJsonPath('error_code', 'NO_WHATSAPP_NUMBER');

        Http::assertNothingSent();
    }

    public function test_numero_sans_compte_whatsapp_trace_un_echec(): void
    {
        $this->fakeWaha();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['waha.test/api/contacts/check-exists*' => Http::response(['numberExists' => false])]);

        $client = $this->client();
        $this->dette($client, 1000);

        $this->asUser($this->gerant)->postJson("/api/manager/clients/{$client}/rappel")
            ->assertStatus(422)->assertJsonPath('error_code', 'NO_WHATSAPP_ACCOUNT');

        $this->assertSame('echoue', Rappel::sole()->statut->value);
    }
}
