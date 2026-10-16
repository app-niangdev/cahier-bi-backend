<?php

namespace App\Http\Controllers;

use App\Enums\StatutBoutique;
use App\Helpers\ApiResponse;
use App\Http\Requests\BoutiqueRequest;
use App\Models\Boutique;
use App\Services\BoutiqueService;
use App\Services\GerantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Boutiques des propriétaires : seul l'administrateur les crée et les modifie.
 */
class BoutiqueController extends Controller
{
    public function __construct(
        private readonly BoutiqueService $boutiques,
        private readonly GerantService $gerants,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'statut'    => ['nullable', Rule::enum(StatutBoutique::class)],
        ]);

        $boutiques = $this->boutiques->list(
            perPage: (int) $request->input('per_page', 10),
            search: trim((string) $request->input('search', '')),
            tenantId: $filters['tenant_id'] ?? null,
            statut: $filters['statut'] ?? null,
            // Boutiques supprimées comprises : l'administrateur peut les restaurer
            withTrashed: $request->boolean('with_trashed'),
        );

        $boutiques->getCollection()->transform(fn (Boutique $b) => $this->present($b));

        return ApiResponse::paginated($boutiques, 'Liste des boutiques récupérée avec succès');
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->boutiques->find($id), withPlaces: true));
    }

    public function store(BoutiqueRequest $request): JsonResponse
    {
        $boutique = $this->boutiques->create($request->validated());

        return ApiResponse::success($this->present($boutique, withPlaces: true), 'Boutique créée avec succès', 201);
    }

    /** POST accepté en plus de PUT : formulaire multipart avec logo. */
    public function update(BoutiqueRequest $request, string $id): JsonResponse
    {
        $boutique = $this->boutiques->update($id, $request->validated());

        return ApiResponse::success($this->present($boutique, withPlaces: true), 'Boutique modifiée avec succès');
    }

    public function destroy(string $id): JsonResponse
    {
        $this->boutiques->delete($id);

        return ApiResponse::success(null, 'Boutique supprimée. Ses gérants ne peuvent plus se connecter.');
    }

    public function restore(string $id): JsonResponse
    {
        return ApiResponse::success($this->present($this->boutiques->restore($id)), 'Boutique restaurée avec succès.');
    }

    private function present(Boutique $boutique, bool $withPlaces = false): array
    {
        return $boutique->attributesToArray() + [
            'logo_url' => BoutiqueService::logoUrl($boutique->logo_path),
            'tenant' => $boutique->tenant?->only(['id', 'name']),
            'deleted_at' => $boutique->deleted_at?->toIso8601String(),
        ] + ($withPlaces ? ['gerants_places' => $this->gerants->places($boutique)] : []);
    }
}
