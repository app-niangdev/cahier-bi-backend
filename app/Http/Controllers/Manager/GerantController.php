<?php

namespace App\Http\Controllers\Manager;

use App\Enums\RoleName;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\GerantRequest;
use App\Models\Boutique;
use App\Models\User;
use App\Services\GerantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gérants des boutiques du propriétaire connecté. Nombre de gérants limité par boutique
 * selon son abonnement ; un gérant peut être transféré vers une autre de ses boutiques.
 */
class GerantController extends Controller
{
    public function __construct(private readonly GerantService $gerants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['boutique_id' => ['nullable', 'integer']]);

        $gerants = $this->query($request)
            ->with('boutique:id,nom')
            ->when($filters['boutique_id'] ?? null, fn ($q, $id) => $q->where('boutique_id', $id))
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => $this->present($u));

        return ApiResponse::success($gerants);
    }

    public function store(GerantRequest $request): JsonResponse
    {
        $boutique = $this->boutique($request, $request->integer('boutique_id'));
        $gerant = $this->gerants->ajouter($boutique, $request->validated());

        return ApiResponse::success(
            $this->present($gerant),
            'Gérant ajouté. Communiquez-lui son mot de passe provisoire : il le changera à sa première connexion.',
            201,
        );
    }

    public function update(GerantRequest $request, int $id): JsonResponse
    {
        $gerant = $this->gerants->modifier($this->gerant($request, $id), $request->validated());

        return ApiResponse::success($this->present($gerant), 'Gérant modifié avec succès.');
    }

    public function transfer(Request $request, int $id): JsonResponse
    {
        $request->validate(['boutique_id' => ['required', 'integer']], [
            'boutique_id.required' => 'Choisissez la boutique de destination.',
        ]);

        $gerant = $this->gerant($request, $id);
        $cible = $this->boutique($request, $request->integer('boutique_id'));

        return ApiResponse::success(
            $this->present($this->gerants->transferer($gerant, $cible)),
            "Gérant transféré vers « {$cible->nom} ».",
        );
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $gerant = $this->gerants->basculerStatut($this->gerant($request, $id));

        return ApiResponse::success(
            $this->present($gerant),
            $gerant->status ? 'Gérant activé.' : 'Gérant désactivé : il ne peut plus se connecter.',
        );
    }

    public function resetAccess(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']], [
            'password.required' => 'Le mot de passe provisoire est obligatoire.',
            'password.min'      => 'Le mot de passe provisoire doit contenir au moins 8 caractères.',
        ]);

        $gerant = $this->gerants->reinitialiserAcces($this->gerant($request, $id), $data['password']);

        return ApiResponse::success($this->present($gerant), 'Accès réinitialisé : communiquez le mot de passe provisoire au gérant.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->gerants->supprimer($this->gerant($request, $id));

        return ApiResponse::success(null, 'Gérant supprimé. Sa place dans la boutique est libérée.');
    }

    /** Gérants du propriétaire connecté. */
    private function query(Request $request)
    {
        return User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereHas('role', fn ($q) => $q->where('name', RoleName::Gerant->value));
    }

    private function gerant(Request $request, int $id): User
    {
        return $this->query($request)->findOrFail($id);
    }

    /** Boutique du propriétaire connecté (404 pour celle d'un autre). */
    private function boutique(Request $request, int $id): Boutique
    {
        return $request->user()->boutiquesAccessibles()->findOrFail($id);
    }

    private function present(User $gerant): array
    {
        return $gerant->only(['id', 'first_name', 'last_name', 'full_name', 'phone_one', 'phone_two', 'address', 'status']) + [
            'phone_verified' => $gerant->phone_verified_at !== null,
            'must_change_password' => (bool) $gerant->must_change_password,
            'boutique' => $gerant->boutique?->only(['id', 'nom']),
        ];
    }
}
