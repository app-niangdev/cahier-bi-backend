<?php

namespace App\Services;

use App\Enums\StatutDette;
use App\Exceptions\ApiException;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\Dette;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dettes des clients. Chaque achat à crédit est une nouvelle dette, avec sa propre échéance,
 * même si le client doit encore sur les précédentes.
 */
class DetteService
{
    /**
     * @param array{montant: numeric, date_creation?: ?string, date_echeance?: ?string, note?: ?string} $data
     */
    public function creer(Client $client, array $data): Dette
    {
        return DB::transaction(function () use ($client, $data) {
            // Verrou sur la boutique : numérotation sans doublon
            $boutique = Boutique::lockForUpdate()->findOrFail($client->boutique_id);
            $numero = Dette::withTrashed()->where('boutique_id', $boutique->id)->count() + 1;

            $dette = Dette::create([
                'boutique_id' => $boutique->id,
                'client_id' => $client->id,
                'reference' => 'D-' . str_pad((string) $numero, 4, '0', STR_PAD_LEFT),
                'montant' => $data['montant'],
                'montant_paye' => 0,
                'montant_restant' => $data['montant'],
                'date_creation' => $data['date_creation'] ?? today()->toDateString(),
                'date_echeance' => $data['date_echeance'] ?? null,
                'note' => $data['note'] ?? null,
                'statut' => StatutDette::EnAttente,
            ]);

            // Statut d'après l'échéance (une échéance passée met directement la dette en retard)
            $dette->recalculer();

            return $dette;
        });
    }

    /**
     * Échéance et note : propriétaire et gérant. Montant : propriétaire seulement,
     * et jamais en dessous de ce que le client a déjà payé.
     *
     * @throws ApiException
     */
    public function modifier(Dette $dette, array $data, User $par): Dette
    {
        return DB::transaction(function () use ($dette, $data, $par) {
            $dette = Dette::lockForUpdate()->findOrFail($dette->id);

            if ($dette->statut === StatutDette::Annulee) {
                throw new ApiException('Une dette annulée ne peut plus être modifiée.', 'DETTE_CLOSE', 422);
            }

            if (array_key_exists('montant', $data)) {
                if (!$par->isProprietaire()) {
                    throw new ApiException('Seul le propriétaire peut modifier le montant d\'une dette.', 'FORBIDDEN', 403);
                }

                if ((float) $data['montant'] < (float) $dette->montant_paye) {
                    throw new ApiException(
                        'Le montant ne peut pas être inférieur à ce que le client a déjà payé ('
                            . number_format((float) $dette->montant_paye, 0, ',', ' ') . ' F).',
                        'MONTANT_INFERIEUR_AU_PAYE',
                        422,
                    );
                }
            }

            $dette->fill(array_intersect_key($data, array_flip(['montant', 'date_echeance', 'note'])));
            $dette->recalculer();

            return $dette;
        });
    }

    /**
     * Annulation (erreur de saisie, marchandise rendue…) : seulement sans paiement.
     * S'il y en a, il faut d'abord annuler les versements concernés.
     *
     * @throws ApiException
     */
    public function annuler(Dette $dette): Dette
    {
        return DB::transaction(function () use ($dette) {
            $dette = Dette::lockForUpdate()->findOrFail($dette->id);

            if ($dette->paiements()->exists()) {
                throw new ApiException(
                    'Cette dette a déjà reçu des paiements : annulez d\'abord les versements concernés.',
                    'DETTE_HAS_PAYMENTS',
                    422,
                );
            }

            $dette->update(['statut' => StatutDette::Annulee]);

            return $dette;
        });
    }
}
