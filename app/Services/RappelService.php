<?php

namespace App\Services;

use App\Enums\CanalRappel;
use App\Enums\StatutDette;
use App\Enums\StatutRappel;
use App\Enums\TonRappel;
use App\Exceptions\ApiException;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Rappel;
use App\Support\SenegalPhone;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Rappel d'un client sur WhatsApp (WAHA) : un message poli pour le total qu'il doit encore.
 * Chaque envoi est tracé dans `rappels`, rattaché à sa plus ancienne dette non soldée.
 */
class RappelService
{
    public function __construct(private readonly WahaService $waha)
    {
    }

    /**
     * Message qui sera envoyé et numéro WhatsApp joint.
     *
     * @return array{message: string, telephone: string, total_du: float}
     *
     * @throws ApiException
     */
    public function apercu(Client $client): array
    {
        [$total, , $telephone] = $this->contexte($client);

        return [
            'message' => $this->message($client, $total),
            'telephone' => $telephone,
            'total_du' => $total,
        ];
    }

    /**
     * Envoie le rappel sur WhatsApp.
     *
     * @throws ApiException
     */
    public function envoyer(Client $client): Rappel
    {
        [$total, $dette, $telephone] = $this->contexte($client);
        $message = $this->message($client, $total);

        $rappel = Rappel::create([
            'dette_id' => $dette->id,
            'boutique_id' => $client->boutique_id,
            'canal' => CanalRappel::Whatsapp,
            'ton' => TonRappel::Poli,
            'statut' => StatutRappel::EnAttente,
            'message' => $message,
        ]);

        $chatId = (string) SenegalPhone::chatId($telephone);

        if (!$this->waha->isConfigured()) {
            if (app()->environment(['local', 'testing'])) {
                // Développement sans WAHA : le message est lisible dans storage/logs
                Log::info('Rappel (WAHA non configuré)', ['client_id' => $client->id, 'message' => $message]);

                return $this->marquer($rappel, StatutRappel::Envoye);
            }

            $this->echec($rappel, 'WAHA non configuré', 'L\'envoi WhatsApp est indisponible pour le moment.', 'WHATSAPP_UNAVAILABLE', 503);
        }

        if ($this->waha->numberExists($chatId) === false) {
            $this->echec(
                $rappel,
                'Numéro sans compte WhatsApp',
                'Le ' . SenegalPhone::format($telephone) . ' n\'a pas de compte WhatsApp : corrigez le numéro sur la fiche du client.',
                'NO_WHATSAPP_ACCOUNT',
            );
        }

        try {
            $this->waha->sendText($chatId, $message);
        } catch (\Throwable $e) {
            $detail = $e instanceof RequestException ? $e->response->body() : null;
            $this->echec($rappel, trim($e->getMessage() . ' ' . $detail), 'Le message WhatsApp n\'a pas pu être envoyé. Réessayez dans un instant.', 'WHATSAPP_SEND_FAILED', 502);
        }

        Log::info('Rappel envoyé', ['client_id' => $client->id, 'rappel_id' => $rappel->id]);

        return $this->marquer($rappel, StatutRappel::Envoye);
    }

    /**
     * Total dû, plus ancienne dette non soldée et numéro WhatsApp du client.
     *
     * @return array{0: float, 1: Dette, 2: string}
     *
     * @throws ApiException
     */
    private function contexte(Client $client): array
    {
        $ouvertes = $client->dettes()
            ->whereIn('statut', StatutDette::ouverts())
            ->orderBy('date_creation')
            ->orderBy('id')
            ->get();

        $total = round((float) $ouvertes->sum('montant_restant'), 2);

        if ($ouvertes->isEmpty() || $total <= 0) {
            throw new ApiException($client->nom . ' ne doit rien : aucun rappel à envoyer.', 'CLIENT_SANS_DETTE', 422);
        }

        $telephone = SenegalPhone::normalize($client->whatsapp) ?? SenegalPhone::normalize($client->telephone);

        if ($telephone === null) {
            throw new ApiException($client->nom . ' n\'a pas de numéro WhatsApp valide : ajoutez-le sur sa fiche.', 'NO_WHATSAPP_NUMBER', 422);
        }

        return [$total, $ouvertes->first(), $telephone];
    }

    /** Message poli : total dû, boutique et plus ancienne échéance dépassée ou à venir. */
    public function message(Client $client, float $total): string
    {
        $boutique = $client->boutique?->nom ?? 'la boutique';
        $echeance = $client->dettes()
            ->whereIn('statut', StatutDette::ouverts())
            ->whereNotNull('date_echeance')
            ->min('date_echeance');
        $date = $echeance ? ' (échéance du ' . \Carbon\Carbon::parse($echeance)->locale('fr')->translatedFormat('j F Y') . ')' : '';
        $montant = number_format($total, 0, ',', ' ') . ' FCFA';

        return "Bonjour {$client->nom}, j'espère que vous allez bien. Petit rappel amical : il reste {$montant} à régler chez {$boutique}{$date}. Merci beaucoup !";
    }

    private function marquer(Rappel $rappel, StatutRappel $statut): Rappel
    {
        $rappel->update(['statut' => $statut, 'envoye_le' => now()]);

        return $rappel;
    }

    /** @throws ApiException */
    private function echec(Rappel $rappel, string $erreur, string $message, string $code, int $status = 422): never
    {
        $rappel->update(['statut' => StatutRappel::Echoue, 'erreur' => $erreur]);
        Log::warning('Rappel non envoyé', ['rappel_id' => $rappel->id, 'erreur' => $erreur]);

        throw new ApiException($message, $code, $status);
    }
}
