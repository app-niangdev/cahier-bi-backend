<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Compte d'un propriétaire : détient l'abonnement et regroupe ses boutiques.
 */
#[Fillable([
    'name', 'code_website', 'slogan', 'description',
    'address', 'email', 'trade', 'ninea', 'rccm',
    'phone_other', 'phone_call', 'phone_whatsapp',
    'logo_url', 'snap', 'instagram', 'facebook', 'tiktok',
    'primary_color', 'secondary_color', 'accent_color',
    'short_name', 'state',
    'approval_status', 'approval_reviewed_at', 'approval_reviewed_by', 'rejection_reason',
])]
class Tenant extends Model
{
    use SoftDeletes;

    /**
     * Inscription faite depuis l'application : « pending » jusqu'à la confirmation du numéro
     * par code WhatsApp, puis « approved ». Créée par l'administrateur : « approved » d'office.
     */
    public const APPROVED = 'approved';
    public const PENDING = 'pending';

    protected function casts(): array
    {
        return [
            'state' => 'boolean',
            'approval_reviewed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function isPending(): bool
    {
        return $this->approval_status === self::PENDING;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Charte graphique transmise aux applications (thème mobile, PDF). */
    public function branding(): array
    {
        return [
            'name' => $this->name,
            'short_name' => $this->short_name,
            'slogan' => $this->slogan,
            'logo_url' => $this->logo_url,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'accent_color' => $this->accent_color,
        ];
    }
}
