<?php

namespace App\Services;

use App\Enums\StatutDette;
use App\Exceptions\ApiException;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encaissements.
 *
 * Versement global du client (« Modou a payé 7 000 ») : réparti sur ses dettes non soldées,
 * de la plus ancienne à la plus récente. Versement sur une dette précise : imputé sur elle seule.
 * Dans les deux cas une ligne de paiement par dette, reliées par le même `versement`,
 * et jamais plus que le reste dû. Les dettes concernées sont verrouillées pendant l'opération :
 * deux encaissements simultanés ne peuvent pas dépasser le reste.
 */
class PaiementService
{
    /**
     * @param array{montant: numeric, methode: string, date_paiement?: ?string, reference?: ?string, note?: ?string} $data
     *
     * @throws ApiException
     */
    public function encaisserClient(Client $client, array $data, User $par): array
    {
        return DB::transaction(function () use ($client, $data, $par) {
            $dettes = Dette::where('client_id', $client->id)
                ->whereIn('statut', StatutDette::ouverts())
                ->orderBy('date_creation')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $this->repartir($client, $dettes, $data, $par);
        });
    }

    /**
     * @throws ApiException
     */
    public function encaisserDette(Dette $dette, array $data, User $par): array
    {
        return DB::transaction(function () use ($dette, $data, $par) {
            $dette = Dette::lockForUpdate()->findOrFail($dette->id);

            if (!$dette->statut->estOuvert()) {
                throw new ApiException(
                    $dette->statut === StatutDette::Payee ? 'Cette dette est déjà soldée.' : 'Cette dette est annulée.',
                    'DETTE_CLOSE',
                    422,
                );
            }

            return $this->repartir($dette->client, collect([$dette]), $data, $par);
        });
    }

    /**
     * Annule un versement saisi par erreur : toutes ses lignes, puis les dettes concernées
     * sont recalculées (une dette soldée peut repasser en attente ou en retard).
     *
     * @param Builder $boutiques boutiques accessibles à l'utilisateur
     *
     * @throws ApiException
     */
    public function annulerVersement(string $versement, Builder $boutiques): array
    {
        return DB::transaction(function () use ($versement, $boutiques) {
            $paiements = Paiement::where('versement', $versement)
                ->whereIn('boutique_id', $boutiques->select('id'))
                ->lockForUpdate()
                ->get();

            if ($paiements->isEmpty()) {
                throw new ApiException('Versement introuvable.', 'VERSEMENT_NOT_FOUND', 404);
            }

            $paiements->each->delete();

            $dettes = Dette::whereIn('id', $paiements->pluck('dette_id'))->lockForUpdate()->get();
            $dettes->each->recalculer();

            return [
                'versement' => $versement,
                'montant' => $this->somme($paiements),
                'dettes' => $dettes->map(fn (Dette $d) => $this->resumeDette($d))->values(),
            ];
        });
    }

    /**
     * @param Collection<int, Dette> $dettes dettes ouvertes, verrouillées, dans l'ordre d'imputation
     *
     * @throws ApiException
     */
    private function repartir(Client $client, Collection $dettes, array $data, User $par): array
    {
        $montant = $this->centimes($data['montant']);
        $reste = $dettes->sum(fn (Dette $d) => $this->centimes($d->montant_restant));

        if ($reste === 0) {
            throw new ApiException("{$client->nom} n'a aucune dette à régler.", 'AUCUNE_DETTE', 422);
        }

        if ($montant > $reste) {
            throw new ApiException(
                'Le montant dépasse le reste dû (' . number_format($reste / 100, 0, ',', ' ') . ' F).',
                'MONTANT_SUPERIEUR_AU_RESTE',
                422,
                ['reste_du' => $reste / 100],
            );
        }

        $versement = (string) Str::uuid();
        $repartition = [];

        foreach ($dettes as $dette) {
            if ($montant === 0) {
                break;
            }

            $part = min($montant, $this->centimes($dette->montant_restant));
            if ($part === 0) {
                continue;
            }

            Paiement::create([
                'dette_id' => $dette->id,
                'boutique_id' => $dette->boutique_id,
                'client_id' => $dette->client_id,
                'enregistre_par' => $par->id,
                'versement' => $versement,
                'montant' => $part / 100,
                'date_paiement' => $data['date_paiement'] ?? today()->toDateString(),
                'methode' => $data['methode'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $dette->recalculer();
            $montant -= $part;

            $repartition[] = ['montant' => $part / 100] + $this->resumeDette($dette);
        }

        return [
            'versement' => $versement,
            'montant' => $this->centimes($data['montant']) / 100,
            'repartition' => $repartition,
            'client' => ['id' => $client->id, 'nom' => $client->nom, 'total_du' => $client->totalDu()],
        ];
    }

    private function resumeDette(Dette $dette): array
    {
        return [
            'dette_id' => $dette->id,
            'reference' => $dette->reference,
            'montant_restant' => (float) $dette->montant_restant,
            'statut' => $dette->statut,
        ];
    }

    /** Montants en centimes : pas d'erreurs d'arrondi dans la répartition. */
    private function centimes(mixed $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    private function somme(Collection $paiements): float
    {
        return $paiements->sum(fn (Paiement $p) => $this->centimes($p->montant)) / 100;
    }
}
