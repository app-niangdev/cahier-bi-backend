<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nouvel achat à crédit d'un client, ou modification d'une dette
 * (le montant reste réservé au propriétaire, vérifié par le service).
 */
class DetteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBoutiquier() ?? false;
    }

    public function rules(): array
    {
        $creation = $this->route('id') === null;

        return [
            'client_id'     => [$creation ? 'required' : 'prohibited', 'integer'],
            'montant'       => [$creation ? 'required' : 'sometimes', 'required', 'numeric', 'min:1', 'max:9999999999'],
            'date_creation' => [$creation ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'before_or_equal:today'],
            'date_echeance' => ['nullable', 'date_format:Y-m-d'],
            'note'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required'   => 'Choisissez le client.',
            'client_id.prohibited' => 'Une dette ne change pas de client.',
            'montant.required'     => 'Le montant est obligatoire.',
            'montant.numeric'      => 'Le montant doit être un nombre.',
            'montant.min'          => 'Le montant doit être supérieur à 0.',
            'date_creation.date_format' => 'Date invalide (format attendu : AAAA-MM-JJ).',
            'date_creation.before_or_equal' => 'La date de la dette ne peut pas être dans le futur.',
            'date_creation.prohibited' => 'La date d\'une dette ne se modifie pas.',
            'date_echeance.date_format' => 'Échéance invalide (format attendu : AAAA-MM-JJ).',
        ];
    }
}
