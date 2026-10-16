<?php

namespace App\Http\Controllers\Manager;

use App\Enums\StatutDette;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Manager\Concerns\ScopesBoutiques;
use App\Http\Requests\DetteRequest;
use App\Http\Requests\PaiementRequest;
use App\Models\Dette;
use App\Models\Paiement;
use App\Services\DetteService;
use App\Services\PaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dettes : chaque achat à crédit en crée une nouvelle, même si le client doit encore.
 */
class DetteController extends Controller
{
    use ScopesBoutiques;

    public function __construct(
        private readonly DetteService $dettes,
        private readonly PaiementService $paiements,
    ) {}

    /** Filtres : `statut`, `ouvertes=1` (non soldées), `client_id`, `boutique_id`. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'statut' => ['nullable', Rule::enum(StatutDette::class)],
            'ouvertes' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'integer'],
            'boutique_id' => ['nullable', 'integer'],
        ]);

        $dettes = Dette::query()
            ->with('client:id,nom,telephone')
            ->whereIn('boutique_id', $this->boutiqueIds($request))
            ->when($filters['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($request->boolean('ouvertes'), fn ($q) => $q->whereIn('statut', StatutDette::ouverts()))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['boutique_id'] ?? null, fn ($q, $id) => $q->where('boutique_id', $id))
            ->latest('date_creation')
            ->latest('id')
            ->paginate((int) $request->input('per_page', 20));

        $dettes->getCollection()->transform(fn (Dette $d) => $this->presentDette($d) + [
            'client' => $d->client?->only(['id', 'nom', 'telephone']),
        ]);

        return ApiResponse::paginated($dettes, 'Liste des dettes récupérée avec succès');
    }

    public function store(DetteRequest $request): JsonResponse
    {
        $client = $this->clientOf($request, $request->integer('client_id'));
        $dette = $this->dettes->creer($client, $request->validated());

        return ApiResponse::success(
            $this->presentDette($dette) + ['client_total_du' => $client->totalDu()],
            'Dette enregistrée.',
            201,
        );
    }

    /** Détail : la dette et ses paiements. */
    public function show(Request $request, int $id): JsonResponse
    {
        $dette = $this->detteOf($request, $id)->load(['client:id,nom,telephone', 'paiements.enregistrePar:id,first_name,last_name']);

        return ApiResponse::success($this->presentDette($dette) + [
            'client' => $dette->client?->only(['id', 'nom', 'telephone']),
            'paiements' => $dette->paiements->sortByDesc('date_paiement')->values()->map(fn (Paiement $p) => [
                'id' => $p->id,
                'versement' => $p->versement,
                'montant' => (float) $p->montant,
                'date_paiement' => $p->date_paiement?->toDateString(),
                'methode' => $p->methode,
                'reference' => $p->reference,
                'enregistre_par' => $p->enregistrePar?->full_name,
            ]),
        ]);
    }

    public function update(DetteRequest $request, int $id): JsonResponse
    {
        $dette = $this->dettes->modifier($this->detteOf($request, $id), $request->validated(), $request->user());

        return ApiResponse::success($this->presentDette($dette), 'Dette modifiée.');
    }

    /** Versement imputé sur cette dette seulement. */
    public function payer(PaiementRequest $request, int $id): JsonResponse
    {
        $resultat = $this->paiements->encaisserDette($this->detteOf($request, $id), $request->validated(), $request->user());

        return ApiResponse::success($resultat, 'Paiement enregistré.', 201);
    }

    /** Réservé au propriétaire. */
    public function annuler(Request $request, int $id): JsonResponse
    {
        $dette = $this->dettes->annuler($this->detteOf($request, $id));

        return ApiResponse::success($this->presentDette($dette), 'Dette annulée.');
    }

    /** Annule un versement saisi par erreur (toutes ses lignes). Réservé au propriétaire. */
    public function annulerVersement(Request $request, string $versement): JsonResponse
    {
        $resultat = $this->paiements->annulerVersement($versement, $request->user()->boutiquesAccessibles());

        return ApiResponse::success($resultat, 'Versement annulé : les dettes concernées sont mises à jour.');
    }
}
