<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\Boutique;
use App\Models\Role;
use App\Models\User;
use App\Support\SenegalPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gérants des boutiques d'un propriétaire.
 *
 * - Un gérant travaille dans une seule boutique.
 * - Chaque boutique accepte au plus `max_gerants_par_boutique` gérants, selon l'abonnement en cours
 *   du propriétaire (forfait ou essai). Les gérants désactivés occupent toujours leur place :
 *   seule la suppression en libère une.
 * - Une boutique au-dessus de la limite (forfait réduit, abonnement expiré) garde ses gérants,
 *   mais n'en accepte plus de nouveaux, ni par ajout ni par transfert.
 * - Un gérant peut être transféré vers une autre boutique du même propriétaire.
 *
 * La boutique visée est verrouillée pendant la vérification : deux ajouts simultanés
 * ne peuvent pas dépasser la limite.
 */
class GerantService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WahaService $waha,
    ) {}

    /**
     * Places de la boutique : limite (null = illimité), gérants affectés et places restantes.
     *
     * @return array{max: ?int, utilises: int, restantes: ?int}
     */
    public function places(Boutique $boutique): array
    {
        $max = $this->subscriptions->maxGerantsParBoutique($boutique->tenant);
        $utilises = $boutique->gerants()->count();

        return [
            'max' => $max,
            'utilises' => $utilises,
            'restantes' => $max === null ? null : max(0, $max - $utilises),
        ];
    }

    /**
     * Crée le compte d'un gérant. Le mot de passe est provisoire : à sa première connexion,
     * le gérant reçoit un code WhatsApp puis choisit le sien.
     *
     * @param array{first_name:string, last_name:string, phone_one:string, password:string,
     *              phone_two?:?string, address?:?string, email?:?string} $data
     *
     * @throws ApiException|ValidationException
     */
    public function ajouter(Boutique $boutique, array $data): User
    {
        $this->ensureWhatsapp($data['phone_one']);

        return DB::transaction(function () use ($boutique, $data) {
            $boutique = $this->verrouiller($boutique);
            $this->verifierPlace($boutique);

            return User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone_one' => $data['phone_one'],
                'phone_two' => $data['phone_two'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'password' => $data['password'],
                'status' => true,
                'must_change_password' => true,
                'phone_verified_at' => null,
                'role_id' => Role::where('name', RoleName::Gerant->value)->value('id'),
                'tenant_id' => $boutique->tenant_id,
                'boutique_id' => $boutique->id,
            ])->load(['role', 'boutique']);
        });
    }

    /**
     * Affecte le gérant à une autre boutique du même propriétaire.
     *
     * @throws ApiException
     */
    public function transferer(User $gerant, Boutique $cible): User
    {
        if (!$gerant->isGerant()) {
            throw new ApiException('Seul un gérant peut être transféré.', 'NOT_A_GERANT', 422);
        }

        if ($cible->tenant_id !== $gerant->tenant_id) {
            throw new ApiException(
                'Un gérant ne peut être transféré que vers une boutique du même propriétaire.',
                'TRANSFER_OTHER_OWNER',
                422,
            );
        }

        if ($cible->id === $gerant->boutique_id) {
            throw new ApiException('Ce gérant travaille déjà dans cette boutique.', 'TRANSFER_SAME_BOUTIQUE', 422);
        }

        return DB::transaction(function () use ($gerant, $cible) {
            $cible = $this->verrouiller($cible);
            $this->verifierPlace($cible);

            $gerant->update(['boutique_id' => $cible->id]);

            return $gerant->fresh(['role', 'boutique']);
        });
    }

    /**
     * Informations du gérant. Un nouveau numéro devra être confirmé par code WhatsApp
     * et ferme ses sessions.
     *
     * @throws ValidationException
     */
    public function modifier(User $gerant, array $data): User
    {
        $data = array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone_one', 'phone_two', 'address']));

        if (isset($data['phone_one']) && $data['phone_one'] !== $gerant->phone_one) {
            $this->ensureWhatsapp($data['phone_one']);
            $data['phone_verified_at'] = null;
            $data['token_version'] = $gerant->token_version + 1;
        }

        $gerant->forceFill($data)->save();

        return $gerant->fresh(['role', 'boutique']);
    }

    /** Active ou désactive le compte ; une désactivation ferme ses sessions. */
    public function basculerStatut(User $gerant): User
    {
        $gerant->forceFill([
            'status' => !$gerant->status,
            'token_version' => $gerant->token_version + 1,
        ])->save();

        return $gerant->fresh(['role', 'boutique']);
    }

    /**
     * Nouveau mot de passe provisoire : le gérant refera le parcours de première connexion.
     */
    public function reinitialiserAcces(User $gerant, string $motDePasseProvisoire): User
    {
        $gerant->forceFill([
            'password' => $motDePasseProvisoire,
            'must_change_password' => true,
            'token_version' => $gerant->token_version + 1,
        ])->save();

        return $gerant->fresh(['role', 'boutique']);
    }

    /** Supprime le compte (récupérable par l'administrateur) et libère sa place. */
    public function supprimer(User $gerant): void
    {
        $gerant->revokeTokens();
        $gerant->delete();
    }

    /**
     * Réactivation d'un gérant supprimé : il reprend une place dans sa boutique.
     *
     * @throws ApiException
     */
    public function verifierPlacePourRestauration(User $gerant): void
    {
        if (!$gerant->boutique_id) {
            return;
        }

        DB::transaction(function () use ($gerant) {
            $this->verifierPlace($this->verrouiller(Boutique::findOrFail($gerant->boutique_id)));
        });
    }

    /**
     * @throws ApiException
     */
    private function verifierPlace(Boutique $boutique): void
    {
        if (!$boutique->estActive()) {
            throw new ApiException(
                'Cette boutique n\'est pas active : impossible d\'y affecter un gérant.',
                'BOUTIQUE_INACTIVE',
                422,
            );
        }

        $places = $this->places($boutique);

        if ($places['restantes'] === 0) {
            throw new ApiException(
                $places['max'] === 0
                    ? 'Votre abonnement ne permet pas d\'ajouter de gérant. Renouvelez-le ou changez de forfait.'
                    : "La boutique « {$boutique->nom} » a atteint sa limite de {$places['max']} gérant"
                        . ($places['max'] > 1 ? 's' : '') . '. Passez à un forfait supérieur pour en ajouter.',
                'GERANTS_LIMIT_REACHED',
                422,
                ['places' => $places],
            );
        }
    }

    /** Relit la boutique en la verrouillant jusqu'à la fin de la transaction. */
    private function verrouiller(Boutique $boutique): Boutique
    {
        return Boutique::with('tenant')->lockForUpdate()->findOrFail($boutique->id);
    }

    /**
     * Les codes de connexion partent sur WhatsApp : le numéro doit y avoir un compte.
     * Si WAHA ne peut pas répondre, on ne bloque pas la saisie.
     *
     * @throws ValidationException
     */
    private function ensureWhatsapp(string $phone): void
    {
        if ($this->waha->numberExists((string) SenegalPhone::chatId($phone)) === false) {
            throw ValidationException::withMessages([
                'phone_one' => "Ce numéro n'a pas de compte WhatsApp : le gérant ne pourrait pas recevoir ses codes de connexion.",
            ]);
        }
    }
}
