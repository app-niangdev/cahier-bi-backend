<?php

namespace App\Http\Requests;

use App\Enums\StatutBoutique;
use App\Support\SenegalPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création (propriétaire obligatoire) et modification d'une boutique par l'administrateur.
 */
class BoutiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['telephone', 'whatsapp'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => SenegalPhone::clean($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $creation = $this->route('id') === null;
        $phone = ['nullable', 'string', 'regex:' . SenegalPhone::PATTERN];

        return [
            'tenant_id'   => [$creation ? 'required' : 'prohibited', Rule::exists('tenants', 'id')->whereNull('deleted_at')],
            'nom'         => [$creation ? 'required' : 'sometimes', 'required', 'string', 'min:2', 'max:150'],
            'telephone'   => $phone,
            'whatsapp'    => $phone,
            'adresse'     => ['nullable', 'string', 'max:255'],
            'quartier'    => ['nullable', 'string', 'max:100'],
            'ville'       => ['nullable', 'string', 'max:100'],
            'statut'      => ['nullable', Rule::enum(StatutBoutique::class)],
            'logo'        => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_id.required'  => 'Le propriétaire de la boutique est obligatoire.',
            'tenant_id.exists'    => 'Le propriétaire sélectionné est invalide.',
            'tenant_id.prohibited' => 'Une boutique ne change pas de propriétaire.',
            'nom.required'        => 'Le nom de la boutique est obligatoire.',
            'nom.min'             => 'Le nom de la boutique doit contenir au moins 2 caractères.',
            'telephone.regex'     => 'Le téléphone doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'whatsapp.regex'      => 'Le numéro WhatsApp doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'statut.enum'         => 'Le statut doit être actif, inactif ou suspendu.',
            'logo.image'          => 'Le logo doit être une image.',
            'logo.max'            => 'Le logo ne doit pas dépasser 2 Mo.',
        ];
    }
}
