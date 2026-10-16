<?php

namespace App\Http\Requests;

use App\Enums\MethodePaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Versement d'un client, global ou sur une dette précise.
 */
class PaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBoutiquier() ?? false;
    }

    public function rules(): array
    {
        return [
            'montant'       => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'methode'       => ['required', Rule::enum(MethodePaiement::class)],
            'date_paiement' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference'     => ['nullable', 'string', 'max:100'],
            'note'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'montant.required' => 'Le montant est obligatoire.',
            'montant.numeric'  => 'Le montant doit être un nombre.',
            'montant.min'      => 'Le montant doit être supérieur à 0.',
            'methode.required' => 'Le moyen de paiement est obligatoire.',
            'methode.enum'     => 'Moyen de paiement invalide (espèces, Wave, Orange Money, Free Money ou autre).',
            'date_paiement.date_format' => 'Date invalide (format attendu : AAAA-MM-JJ).',
            'date_paiement.before_or_equal' => 'La date du paiement ne peut pas être dans le futur.',
        ];
    }
}
