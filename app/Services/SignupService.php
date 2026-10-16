<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\OtpChallenge;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SenegalPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'un propriétaire de boutique depuis l'application mobile.
 *
 * 1. Formulaire : compte propriétaire (tenant) « en attente » et utilisateur, puis code WhatsApp.
 * 2. Code valide : numéro confirmé, le compte est activé avec le forfait gratuit et une première
 *    boutique, et le propriétaire est connecté. Les boutiques suivantes sont créées par l'administrateur.
 */
class SignupService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SubscriptionService $subscriptions,
        private readonly WahaService $waha,
        private readonly BoutiqueService $boutiques,
    ) {}

    /**
     * @throws ValidationException|ApiException
     */
    public function register(array $data, ?string $ip = null): OtpChallenge
    {
        $this->releaseAbandonedSignup($data['phone']);
        $this->ensureWhatsapp($data['phone']);

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'trade' => $data['trade'] ?? null,
                'code_website' => $this->uniqueCode($data['company_name']),
                'phone_call' => $data['phone'],
                'phone_whatsapp' => $data['phone'],
                'address' => $this->adresse($data),
                'approval_status' => Tenant::PENDING,
            ]);

            return User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone_one' => $data['phone'],
                'password' => $data['password'],
                'status' => true,
                'role_id' => Role::where('name', RoleName::Proprietaire->value)->value('id'),
                'tenant_id' => $tenant->id,
                'must_change_password' => false,
                'phone_verified_at' => null,
            ]);
        });

        return $this->otp->start($user, OtpChallenge::SIGNUP, $ip);
    }

    /**
     * Numéro confirmé : le compte est activé avec le forfait gratuit (à partir d'aujourd'hui)
     * et sa boutique, au nom du commerce. Sans effet s'il l'est déjà.
     */
    public function activate(Tenant $tenant): Tenant
    {
        if (!$tenant->isPending()) {
            return $tenant;
        }

        DB::transaction(function () use ($tenant) {
            $tenant->update([
                'approval_status' => Tenant::APPROVED,
                'approval_reviewed_at' => now(),
            ]);

            if (!$tenant->subscriptions()->exists()) {
                $this->subscriptions->createSignupSubscription($tenant);
            }

            if (!$tenant->boutiques()->withTrashed()->exists()) {
                [$quartier, $ville] = array_pad(array_map('trim', explode(',', (string) $tenant->address, 2)), 2, null);
                $this->boutiques->create([
                    'tenant_id' => $tenant->id,
                    'nom' => $tenant->name,
                    'telephone' => $tenant->phone_call,
                    'whatsapp' => $tenant->phone_whatsapp,
                    'quartier' => $quartier ?: null,
                    'ville' => $ville ?: null,
                ]);
            }
        });

        return $tenant->fresh();
    }

    /**
     * Un numéro déjà pris ne peut pas s'inscrire, sauf une inscription abandonnée
     * avant la saisie du code : elle est effacée pour repartir de zéro.
     *
     * @throws ValidationException
     */
    private function releaseAbandonedSignup(string $phone): void
    {
        $existing = User::with('tenant')->where('phone_one', $phone)->first();
        if (!$existing) {
            return;
        }

        $tenant = $existing->tenant;

        if ($tenant?->isPending() && !$existing->phone_verified_at) {
            DB::transaction(function () use ($existing, $tenant) {
                $existing->forceDelete();
                $tenant->forceDelete();
            });

            return;
        }

        throw ValidationException::withMessages([
            'phone' => 'Ce numéro est déjà inscrit. Connectez-vous ou utilisez « Mot de passe oublié ».',
        ]);
    }

    /**
     * Les codes partent sur WhatsApp : le numéro doit y avoir un compte.
     * Si WAHA ne peut pas répondre, on ne bloque pas l'inscription.
     *
     * @throws ValidationException
     */
    private function ensureWhatsapp(string $phone): void
    {
        if ($this->waha->numberExists((string) SenegalPhone::chatId($phone)) === false) {
            throw ValidationException::withMessages([
                'phone' => 'Ce numéro n\'a pas de compte WhatsApp : vous ne pourriez pas recevoir votre code de confirmation.',
            ]);
        }
    }

    /** « Médina, Dakar » : adresse du compte, reprise par sa première boutique. */
    private function adresse(array $data): ?string
    {
        $parts = array_filter([$data['quartier'] ?? null, $data['ville'] ?? null]);

        return $parts ? implode(', ', $parts) : null;
    }

    /** Code unique du compte, dérivé de son nom (« boutique-diop-k3f9 »). */
    private function uniqueCode(string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'boutique';

        do {
            $code = $base . '-' . Str::lower(Str::random(4));
        } while (Tenant::withTrashed()->where('code_website', $code)->exists());

        return $code;
    }
}
