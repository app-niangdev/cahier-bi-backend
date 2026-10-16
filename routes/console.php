<?php

use App\Enums\StatutDette;
use App\Models\Dette;
use App\Models\Role;
use App\Models\User;
use App\Support\SenegalPhone;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Premier administrateur en production (UserSeeder ne crée que des comptes de démonstration) :
// docker compose exec backend php artisan admin:create
Artisan::command('admin:create', function () {
    $role = Role::where('name', 'ADMIN')->firstOrFail();

    $email = $this->ask('E-mail');
    if (User::withTrashed()->where('email', $email)->exists()) {
        $this->error('Cet e-mail est déjà utilisé.');

        return 1;
    }

    $firstName = $this->ask('Prénom');
    $lastName = $this->ask('Nom');
    $phone = $this->ask('Téléphone (9 chiffres)');
    $password = $this->secret('Mot de passe (8 caractères minimum)');

    if (strlen((string) $password) < 8) {
        $this->error('Mot de passe trop court.');

        return 1;
    }

    User::create([
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'phone_one'  => SenegalPhone::normalize($phone) ?? $phone,
        'password'   => $password,
        'status'     => true,
        'role_id'    => $role->id,
    ]);

    $this->info("Administrateur {$email} créé.");
})->purpose('Créer un administrateur de la plateforme');

// Statuts qui dépendent de la date : « à payer aujourd'hui » et « en retard ».
// Les dettes payées ou annulées ne sont pas touchées.
Artisan::command('dettes:actualiser-statuts', function () {
    $today = today()->toDateString();
    $ouvertes = fn () => Dette::whereIn('statut', StatutDette::ouverts());

    $enRetard = $ouvertes()->whereDate('date_echeance', '<', $today)
        ->update(['statut' => StatutDette::EnRetard->value]);
    $aujourdhui = $ouvertes()->whereDate('date_echeance', $today)
        ->update(['statut' => StatutDette::AAujourdhui->value]);
    $enAttente = $ouvertes()->where(fn ($q) => $q->whereNull('date_echeance')->orWhereDate('date_echeance', '>', $today))
        ->update(['statut' => StatutDette::EnAttente->value]);

    $this->info("Dettes en retard : {$enRetard}, à payer aujourd'hui : {$aujourdhui}, en attente : {$enAttente}.");
})->purpose('Recalculer les statuts des dettes selon leur échéance');

Schedule::command('dettes:actualiser-statuts')->dailyAt('00:05')->withoutOverlapping();
