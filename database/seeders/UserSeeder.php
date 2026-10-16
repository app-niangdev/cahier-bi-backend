<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Administrateur de la plateforme : connexion par e-mail
        User::firstOrCreate(['email' => 'niangdev031299@gmail.com'], [
            'first_name' => 'Ibrahima',
            'last_name'  => 'Niang',
            'phone_one'  => '770906538',
            'password'   => 'P@sser1234',
            'address'    => 'Bargny',
            'status'     => true,
            'role_id'    => Role::where('name', RoleName::Admin->value)->value('id'),
        ]);
    }
}
