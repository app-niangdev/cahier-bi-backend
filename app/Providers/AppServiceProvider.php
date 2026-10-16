<?php

namespace App\Providers;

use App\Interfaces\TenantRepositoryInterface;
use App\Interfaces\TenantServiceInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Interfaces\UserServiceInterface;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use App\Services\TenantService;
use App\Services\UserService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(UserServiceInterface::class, UserService::class);
        $this->app->bind(TenantRepositoryInterface::class, TenantRepository::class);
        $this->app->bind(TenantServiceInterface::class, TenantService::class);
    }

    public function boot(): void
    {
        Gate::define('admin', fn ($user) => $user->role?->name === 'ADMIN');

        // Par adresse IP, avec une marge pour les mobiles derrière le NAT des opérateurs.
        // Les limites par compte (mots de passe faux, codes envoyés) sont dans les services.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(40)->by('auth|' . $request->ip()));
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(30)->by('otp|' . $request->ip()));
        // Inscriptions : large (adresses IP partagées par les opérateurs mobiles) mais contre les créations en masse
        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(20)->by('signup|' . $request->ip()));
    }
}
