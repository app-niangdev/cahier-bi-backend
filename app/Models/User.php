<?php

namespace App\Models;

use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'first_name', 'last_name', 'email', 'password',
    'phone_one', 'phone_two', 'username', 'address', 'status', 'role_id',
    'must_change_password', 'tenant_id', 'boutique_id', 'phone_verified_at', 'token_version',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'status'               => 'boolean',
            'must_change_password' => 'boolean',
            'phone_verified_at'    => 'datetime',
            'token_version'        => 'integer',
        ];
    }

    protected $hidden = [
        'password',
        'remember_token',
        'token_version',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    protected $appends = [
        'full_name'
    ];

    public function getFullNameAttribute(): string
    {
        return trim(
            ($this->first_name ?? '') . ' ' .
            ($this->last_name ?? '')
        );
    }

    public function hasRole(RoleName $role): bool
    {
        return $this->role?->name === $role->value;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleName::Admin);
    }

    public function isProprietaire(): bool
    {
        return $this->hasRole(RoleName::Proprietaire);
    }

    public function isGerant(): bool
    {
        return $this->hasRole(RoleName::Gerant);
    }

    /** Utilisateur de l'application mobile, soumis à l'abonnement de son propriétaire. */
    public function isBoutiquier(): bool
    {
        return $this->isProprietaire() || $this->isGerant();
    }

    /** Invalide tous les jetons (accès et rafraîchissement) déjà délivrés. */
    public function revokeTokens(): void
    {
        $this->increment('token_version');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Boutiques que l'utilisateur peut consulter : toutes celles du propriétaire,
     * la sienne seulement pour un gérant, aucune pour l'administrateur.
     */
    public function boutiquesAccessibles(): Builder
    {
        return Boutique::query()
            ->when($this->isProprietaire(), fn ($q) => $q->where('tenant_id', $this->tenant_id))
            ->when($this->isGerant(), fn ($q) => $q->whereKey($this->boutique_id))
            ->when(!$this->isBoutiquier(), fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Boutique du gérant (null pour l'administrateur et le propriétaire). */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
