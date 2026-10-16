<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Forfaits de Cahier Bi. Idempotent : un forfait existant (même nom) n'est pas modifié,
 * car son prix et sa durée sont figés une fois créé.
 *
 * Gérants par boutique : Gratuit 1, Boutique 3, Boutique Pro 10, Annuel illimité (null).
 * PRIX ET DURÉES À CONFIRMER avant la mise en production.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Gratuit',      'duration_months' => 1,  'price' => 0,     'max_gerants_par_boutique' => 1],
            ['name' => 'Boutique',     'duration_months' => 1,  'price' => 3000,  'max_gerants_par_boutique' => 3],
            ['name' => 'Boutique Pro', 'duration_months' => 1,  'price' => 7500,  'max_gerants_par_boutique' => 10],
            ['name' => 'Annuel',       'duration_months' => 12, 'price' => 75000, 'max_gerants_par_boutique' => null],
        ];

        foreach ($plans as $position => $plan) {
            SubscriptionPlan::firstOrCreate(['name' => $plan['name']], $plan + [
                'currency' => config('subscriptions.default_currency', 'XOF'),
                'is_active' => true,
                'position' => $position + 1,
            ]);
        }
    }
}
