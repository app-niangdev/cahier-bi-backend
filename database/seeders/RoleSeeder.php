<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent : rejoué à chaque déploiement
        foreach (RoleName::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value], ['label' => $role->label()]);
        }
    }
}
