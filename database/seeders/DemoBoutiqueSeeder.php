<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Boutique;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;

/**
 * Démonstration : 2 propriétaires, chacun avec une boutique et un gérant. Idempotent.
 *
 * Connexion par téléphone, mot de passe P@sser1234. À la première connexion, code WhatsApp
 * (visible dans storage/logs sans WAHA en local) puis choix du mot de passe.
 */
class DemoBoutiqueSeeder extends Seeder
{
    private const PASSWORD = 'P@sser1234';

    public function run(): void
    {
        $roles = Role::pluck('id', 'name');

        $demos = [
            [
                'tenant' => ['code_website' => 'demo-ndiaye', 'name' => 'Commerce Ndiaye', 'trade' => 'Alimentation générale', 'address' => 'Bargny, Rufisque'],
                'proprietaire' => ['first_name' => 'Moussa', 'last_name' => 'Ndiaye', 'phone_one' => '771234567', 'address' => 'Bargny'],
                'boutique' => ['slug' => 'boutique-ndiaye-bargny', 'nom' => 'Boutique Ndiaye', 'telephone' => '771234567', 'quartier' => 'Bargny Guedj', 'ville' => 'Rufisque'],
                'gerant' => ['first_name' => 'Awa', 'last_name' => 'Diop', 'phone_one' => '761234567'],
            ],
            [
                'tenant' => ['code_website' => 'demo-fall', 'name' => 'Commerce Fall', 'trade' => 'Quincaillerie', 'address' => 'Médina, Dakar'],
                'proprietaire' => ['first_name' => 'Ousmane', 'last_name' => 'Fall', 'phone_one' => '772345678', 'address' => 'Médina'],
                'boutique' => ['slug' => 'boutique-fall-medina', 'nom' => 'Quincaillerie Fall', 'telephone' => '772345678', 'quartier' => 'Médina', 'ville' => 'Dakar'],
                'gerant' => ['first_name' => 'Fatou', 'last_name' => 'Sall', 'phone_one' => '762345678'],
            ],
        ];

        foreach ($demos as $demo) {
            $tenant = Tenant::firstOrCreate(
                ['code_website' => $demo['tenant']['code_website']],
                $demo['tenant'] + ['phone_call' => $demo['proprietaire']['phone_one']],
            );

            // Période d'essai, comme après une inscription depuis l'application
            if (!$tenant->subscriptions()->exists()) {
                app(SubscriptionService::class)->createTrial($tenant);
            }

            $boutique = Boutique::firstOrCreate(
                ['slug' => $demo['boutique']['slug']],
                $demo['boutique'] + ['tenant_id' => $tenant->id],
            );

            User::firstOrCreate(['phone_one' => $demo['proprietaire']['phone_one']], $demo['proprietaire'] + [
                'password'             => self::PASSWORD,
                'status'               => true,
                'must_change_password' => true,
                'role_id'              => $roles[RoleName::Proprietaire->value],
                'tenant_id'            => $tenant->id,
            ]);

            User::firstOrCreate(['phone_one' => $demo['gerant']['phone_one']], $demo['gerant'] + [
                'password'             => self::PASSWORD,
                'status'               => true,
                'must_change_password' => true,
                'role_id'              => $roles[RoleName::Gerant->value],
                'tenant_id'            => $tenant->id,
                'boutique_id'          => $boutique->id,
            ]);
        }
    }
}
