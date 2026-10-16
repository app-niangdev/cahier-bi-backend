<?php

namespace App\Http\Controllers\Manager;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Services\BoutiqueService;
use App\Services\GerantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Boutiques visibles dans l'application : toutes celles du propriétaire, celle du gérant.
 * Lecture seule : elles sont créées et modifiées par l'administrateur.
 */
class BoutiqueController extends Controller
{
    public function __construct(private readonly GerantService $gerants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $boutiques = $user->boutiquesAccessibles()
            ->with('tenant')
            ->orderBy('nom')
            ->get()
            ->map(fn (Boutique $b) => $b->only(['id', 'nom', 'telephone', 'whatsapp', 'adresse', 'quartier', 'ville', 'statut']) + [
                'logo_url' => BoutiqueService::logoUrl($b->logo_path),
                'total_a_recuperer' => $b->totalARecuperer(),
                // Places de gérants : utile au propriétaire seulement
                'gerants_places' => $user->isProprietaire() ? $this->gerants->places($b) : null,
            ]);

        return ApiResponse::success($boutiques);
    }
}
