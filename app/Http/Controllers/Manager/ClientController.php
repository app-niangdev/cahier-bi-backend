<?php

namespace App\Http\Controllers\Manager;

use App\Enums\StatutDette;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Manager\Concerns\ScopesBoutiques;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\PaiementRequest;
use App\Models\Client;
use App\Models\Dette;
use App\Models\Paiement;
use App\Services\PaiementService;
use App\Services\RappelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clients des boutiques, leur situation et leurs versements.
 */
class ClientController extends Controller
{
    use ScopesBoutiques;

    public function __construct(
        private readonly PaiementService $paiements,
        private readonly RappelService $rappels,
    )
    {
    }

    /** Filtres : `search` (nom, téléphone), `boutique_id`, `debiteurs=1` (reste à payer). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'boutique_id' => ['nullable', 'integer'],
            'debiteurs' => ['nullable', 'boolean'],
        ]);

        $clients = Client::query()
            ->whereIn('boutique_id', $this->boutiqueIds($request))
            ->when($filters['boutique_id'] ?? null, fn ($q, $id) => $q->where('boutique_id', $id))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->whereLike('nom', "%{$s}%")
                ->orWhereLike('telephone', "%{$s}%")))
            ->withSum(['dettes as total_du' => fn ($q) => $q->whereIn('statut', StatutDette::ouverts())], 'montant_restant')
            ->withCount(['dettes as dettes_en_retard' => fn ($q) => $q->where('statut', StatutDette::EnRetard)])
            ->when($request->boolean('debiteurs'), fn ($q) => $q->whereHas('dettes', fn ($q) => $q->whereIn('statut', StatutDette::ouverts())))
            ->orderBy('nom')
            ->paginate((int) $request->input('per_page', 20));

        $clients->getCollection()->transform(fn (Client $c) => $this->present($c) + [
            'total_du' => (float) $c->total_du,
            'dettes_en_retard' => (int) $c->dettes_en_retard,
        ]);

        return ApiResponse::paginated($clients, 'Liste des clients récupérée avec succès');
    }

    public function store(ClientRequest $request): JsonResponse
    {
        $user = $request->user();
        $boutiqueId = $user->isGerant() ? $user->boutique_id : $request->integer('boutique_id');
        $boutique = $user->boutiquesAccessibles()->findOrFail($boutiqueId);

        $client = Client::create($request->safe()->except('boutique_id') + ['boutique_id' => $boutique->id]);

        return ApiResponse::success($this->present($client) + ['total_du' => 0.0], 'Client ajouté.', 201);
    }

    /** Fiche du client : ses dettes (non soldées d'abord) et son total dû. */
    public function show(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOf($request, $id);

        $dettes = $client->dettes()
            ->orderByRaw('CASE WHEN statut IN (?, ?, ?) THEN 0 ELSE 1 END', StatutDette::ouverts())
            ->orderBy('date_creation')
            ->orderBy('id')
            ->get();

        return ApiResponse::success($this->present($client) + [
            'total_du' => $client->totalDu(),
            'dettes' => $dettes->map(fn (Dette $d) => $this->presentDette($d)),
        ]);
    }

    public function update(ClientRequest $request, int $id): JsonResponse
    {
        $client = $this->clientOf($request, $id);
        $client->update($request->validated());

        return ApiResponse::success($this->present($client), 'Client modifié.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOf($request, $id);

        if ($client->totalDu() > 0) {
            throw new ApiException('Ce client doit encore de l\'argent : impossible de le supprimer.', 'CLIENT_HAS_DEBT', 422);
        }

        $client->delete();

        return ApiResponse::success(null, 'Client supprimé.');
    }

    /**
     * Versement global : réparti sur les dettes non soldées, de la plus ancienne à la plus récente.
     */
    public function payer(PaiementRequest $request, int $id): JsonResponse
    {
        $resultat = $this->paiements->encaisserClient($this->clientOf($request, $id), $request->validated(), $request->user());

        return ApiResponse::success($resultat, 'Paiement enregistré.', 201);
    }

    /** Historique des versements du client, chacun avec sa répartition par dette. */
    public function versements(Request $request, int $id): JsonResponse
    {
        $client = $this->clientOf($request, $id);

        $versements = Paiement::where('client_id', $client->id)
            ->with(['dette:id,reference', 'enregistrePar:id,first_name,last_name'])
            ->orderByDesc('date_paiement')
            ->orderByDesc('id')
            ->get()
            ->groupBy('versement')
            ->map(function ($lignes, $versement) {
                $premiere = $lignes->first();

                return [
                    'versement' => $versement,
                    'montant' => round($lignes->sum(fn ($p) => (float) $p->montant), 2),
                    'date_paiement' => $premiere->date_paiement?->toDateString(),
                    'methode' => $premiere->methode,
                    'reference' => $premiere->reference,
                    'note' => $premiere->note,
                    'enregistre_par' => $premiere->enregistrePar?->full_name,
                    'repartition' => $lignes->map(fn ($p) => [
                        'dette_id' => $p->dette_id,
                        'reference' => $p->dette?->reference,
                        'montant' => (float) $p->montant,
                    ])->values(),
                ];
            })
            ->values();

        return ApiResponse::success($versements);
    }

    /** Message de rappel (poli) qui serait envoyé sur WhatsApp. */
    public function apercuRappel(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->rappels->apercu($this->clientOf($request, $id)));
    }

    /** Envoie le rappel poli sur WhatsApp (WAHA) pour le total dû par le client. */
    public function rappel(Request $request, int $id): JsonResponse
    {
        $rappel = $this->rappels->envoyer($this->clientOf($request, $id));

        return ApiResponse::success([
            'id' => $rappel->id,
            'statut' => $rappel->statut,
            'envoye_le' => $rappel->envoye_le?->toIso8601String(),
            'message' => $rappel->message,
        ], 'Rappel envoyé sur WhatsApp.', 201);
    }

    private function present(Client $client): array
    {
        return $client->only(['id', 'boutique_id', 'nom', 'telephone', 'whatsapp', 'adresse', 'note']);
    }
}
