<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoutiqueController;
use App\Http\Controllers\Manager\BoutiqueController as ManagerBoutiqueController;
use App\Http\Controllers\Manager\ClientController;
use App\Http\Controllers\Manager\CompanyController;
use App\Http\Controllers\Manager\DashboardController;
use App\Http\Controllers\Manager\DetteController;
use App\Http\Controllers\Manager\GerantController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Préfixe : /api (bootstrap/app.php)

// Version attendue de l'application mobile (public : vérifiée avant la connexion)
Route::get('app-version', AppVersionController::class)->middleware('throttle:60,1');

// Forfaits proposés et contact pour payer (public : affiché aussi au propriétaire bloqué)
Route::get('subscription-offers', [SubscriptionPlanController::class, 'offers'])->middleware('throttle:60,1');

Route::prefix('auth')->name('auth.')->group(function () {

    Route::middleware('throttle:auth')->group(function () {
        Route::post('login',   [AuthController::class, 'login'])->name('login');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
    });

    // Codes OTP WhatsApp : inscription, première connexion, numéro modifié, mot de passe oublié
    Route::middleware('throttle:otp')->group(function () {
        Route::post('register',        [AuthController::class, 'register'])->middleware('throttle:signup')->name('register');
        Route::post('otp/verify',      [AuthController::class, 'verifyOtp'])->name('otp.verify');
        Route::post('otp/resend',      [AuthController::class, 'resendOtp'])->name('otp.resend');
        Route::post('password/set',    [AuthController::class, 'setPassword'])->name('password.set');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password',  [AuthController::class, 'resetPassword'])->name('reset-password');
    });

    Route::middleware('jwt.auth')->group(function () {
        Route::post('logout',          [AuthController::class, 'logout'])->name('logout');
        // Propriétaire et gérants : coupés aussi ici quand l'abonnement a expiré
        Route::middleware('subscription')->group(function () {
            Route::get('me',               [AuthController::class, 'me'])->name('me');
            Route::put('me',               [AuthController::class, 'updateMe'])->name('update-me');
            Route::post('change-password', [AuthController::class, 'changePassword'])->name('change-password');
        });
    });
});

// -----------------------------------------------------------------------------
// Administrateur de la plateforme (application web Angular)
// -----------------------------------------------------------------------------
Route::middleware(['jwt.auth', 'role:ADMIN'])->group(function () {

    Route::get('/admin/dashboard', AdminDashboardController::class);

    Route::prefix('users')->group(function () {
        Route::get('/list',                    [UserController::class, 'index']);
        Route::post('/add',                    [UserController::class, 'store']);
        Route::get('/show/{id}',               [UserController::class, 'show']);
        Route::put('/update/{id}',             [UserController::class, 'update']);
        Route::put('/toggle-status/{id}',      [UserController::class, 'toggleStatus']);
        Route::post('/reset-access/{id}',      [UserController::class, 'resetAccess']);
        Route::delete('/disable/{id}',         [UserController::class, 'disable']);
        Route::delete('/destroy/{id}/force',   [UserController::class, 'destroy']);
        Route::post('/restore/{id}',           [UserController::class, 'restore']);
    });

    Route::prefix('roles')->group(function () {
        Route::get('/list', [RoleController::class, 'index']);
    });

    // Comptes des propriétaires
    Route::prefix('tenants')->group(function () {
        Route::get('/list',                    [TenantController::class, 'index']);
        Route::post('/add',                    [TenantController::class, 'store']);
        Route::get('/show/{id}',               [TenantController::class, 'show']);
        // POST accepté en plus de PUT : formulaire multipart avec logo
        Route::match(['put', 'post'], '/update/{id}', [TenantController::class, 'update']);
        Route::put('/toggle-status/{id}',      [TenantController::class, 'toggleStatus']);
        Route::delete('/disable/{id}',         [TenantController::class, 'disable']);
        Route::delete('/destroy/{id}/force',   [TenantController::class, 'destroy']);
        Route::post('/restore/{id}',           [TenantController::class, 'restore']);
    });

    // Boutiques : créées uniquement par l'administrateur, pour un propriétaire
    Route::prefix('boutiques')->group(function () {
        Route::get('/list',                    [BoutiqueController::class, 'index']);
        Route::post('/add',                    [BoutiqueController::class, 'store']);
        Route::get('/show/{id}',               [BoutiqueController::class, 'show'])->whereNumber('id');
        // POST accepté en plus de PUT : formulaire multipart avec logo
        Route::match(['put', 'post'], '/update/{id}', [BoutiqueController::class, 'update'])->whereNumber('id');
        Route::delete('/delete/{id}',          [BoutiqueController::class, 'destroy'])->whereNumber('id');
        Route::post('/restore/{id}',           [BoutiqueController::class, 'restore'])->whereNumber('id');
    });

    // Types d'abonnement (forfaits) : prix et durée non modifiables, limite de gérants par boutique
    Route::prefix('subscription-plans')->group(function () {
        Route::get('/list',               [SubscriptionPlanController::class, 'index']);
        Route::post('/add',               [SubscriptionPlanController::class, 'store']);
        Route::put('/update/{id}',        [SubscriptionPlanController::class, 'update']);
        Route::put('/toggle-status/{id}', [SubscriptionPlanController::class, 'toggleStatus']);
        Route::delete('/delete/{id}',     [SubscriptionPlanController::class, 'destroy']);
    });

    Route::prefix('subscriptions')->group(function () {
        Route::get('/overview',          [SubscriptionController::class, 'overview']);
        Route::get('/tenant/{tenantId}', [SubscriptionController::class, 'index']);
        Route::post('/add',              [SubscriptionController::class, 'store']);
        Route::put('/update/{id}',       [SubscriptionController::class, 'update']);
        Route::delete('/delete/{id}',    [SubscriptionController::class, 'destroy']);
    });
});

// -----------------------------------------------------------------------------
// Application mobile Flutter : propriétaire et gérants
// Accès coupé si le compte du propriétaire est désactivé ou son abonnement expiré
// (et, pour un gérant, si sa boutique est désactivée).
// -----------------------------------------------------------------------------
Route::prefix('manager')->middleware(['jwt.auth', 'role:PROPRIETAIRE,GERANT', 'subscription'])->group(function () {

    Route::get('/dashboard', DashboardController::class);
    Route::get('/boutiques', [ManagerBoutiqueController::class, 'index']);

    Route::get('/clients',                  [ClientController::class, 'index']);
    Route::post('/clients',                 [ClientController::class, 'store']);
    Route::get('/clients/{id}',             [ClientController::class, 'show'])->whereNumber('id');
    Route::put('/clients/{id}',             [ClientController::class, 'update'])->whereNumber('id');
    Route::delete('/clients/{id}',          [ClientController::class, 'destroy'])->whereNumber('id');
    // Versement global : réparti de la dette la plus ancienne à la plus récente
    Route::post('/clients/{id}/paiements',  [ClientController::class, 'payer'])->whereNumber('id');
    Route::get('/clients/{id}/versements',  [ClientController::class, 'versements'])->whereNumber('id');
    // Rappel poli sur WhatsApp (WAHA) pour le total dû
    Route::get('/clients/{id}/rappel',      [ClientController::class, 'apercuRappel'])->whereNumber('id');
    Route::post('/clients/{id}/rappel',     [ClientController::class, 'rappel'])->whereNumber('id')->middleware('throttle:10,1');

    Route::get('/dettes',                   [DetteController::class, 'index']);
    Route::post('/dettes',                  [DetteController::class, 'store']);
    Route::get('/dettes/{id}',              [DetteController::class, 'show'])->whereNumber('id');
    Route::put('/dettes/{id}',              [DetteController::class, 'update'])->whereNumber('id');
    Route::post('/dettes/{id}/paiements',   [DetteController::class, 'payer'])->whereNumber('id');

    // Réservé au propriétaire
    Route::middleware('role:PROPRIETAIRE')->group(function () {
        Route::post('/dettes/{id}/annuler',     [DetteController::class, 'annuler'])->whereNumber('id');
        Route::delete('/versements/{versement}', [DetteController::class, 'annulerVersement'])->whereUuid('versement');

        Route::get('/company',      [CompanyController::class, 'show']);
        Route::post('/company',     [CompanyController::class, 'update']);
        Route::get('/subscription', [CompanyController::class, 'subscription']);

        Route::get('/gerants',                       [GerantController::class, 'index']);
        Route::post('/gerants',                      [GerantController::class, 'store']);
        Route::put('/gerants/{id}',                  [GerantController::class, 'update'])->whereNumber('id');
        Route::put('/gerants/{id}/transfer',         [GerantController::class, 'transfer'])->whereNumber('id');
        Route::put('/gerants/{id}/toggle-status',    [GerantController::class, 'toggleStatus'])->whereNumber('id');
        Route::post('/gerants/{id}/reset-access',    [GerantController::class, 'resetAccess'])->whereNumber('id');
        Route::delete('/gerants/{id}',               [GerantController::class, 'destroy'])->whereNumber('id');
    });
});
