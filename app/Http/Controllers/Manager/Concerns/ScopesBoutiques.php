<?php

namespace App\Http\Controllers\Manager\Concerns;

use App\Models\Client;
use App\Models\Dette;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Limite les données aux boutiques de l'utilisateur connecté : toutes celles du propriétaire,
 * la sienne pour un gérant. Une ressource d'une autre boutique répond 404.
 */
trait ScopesBoutiques
{
    protected function boutiqueIds(Request $request): Builder
    {
        return $request->user()->boutiquesAccessibles()->select('id');
    }

    protected function clientOf(Request $request, int $id): Client
    {
        return Client::whereIn('boutique_id', $this->boutiqueIds($request))->findOrFail($id);
    }

    protected function detteOf(Request $request, int $id): Dette
    {
        return Dette::whereIn('boutique_id', $this->boutiqueIds($request))->findOrFail($id);
    }

    protected function presentDette(Dette $dette): array
    {
        return $dette->only(['id', 'reference', 'client_id', 'boutique_id', 'note']) + [
            'montant' => (float) $dette->montant,
            'montant_paye' => (float) $dette->montant_paye,
            'montant_restant' => (float) $dette->montant_restant,
            'date_creation' => $dette->date_creation?->toDateString(),
            'date_echeance' => $dette->date_echeance?->toDateString(),
            'statut' => $dette->statut,
            'statut_label' => $dette->statut->label(),
        ];
    }
}
