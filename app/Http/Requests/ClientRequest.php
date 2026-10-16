<?php

namespace App\Http\Requests;

use App\Support\SenegalPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Client d'une boutique. À la création, le propriétaire choisit la boutique ;
 * pour un gérant, c'est la sienne.
 */
class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isBoutiquier() ?? false;
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
            'boutique_id' => [$creation && $this->user()?->isProprietaire() ? 'required' : 'prohibited', 'integer'],
            'nom'         => [$creation ? 'required' : 'sometimes', 'required', 'string', 'min:2', 'max:150'],
            'telephone'   => $phone,
            'whatsapp'    => $phone,
            'adresse'     => ['nullable', 'string', 'max:255'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'boutique_id.required' => 'Choisissez la boutique du client.',
            'boutique_id.prohibited' => 'Un client ne change pas de boutique.',
            'nom.required'    => 'Le nom du client est obligatoire.',
            'nom.min'         => 'Le nom du client doit contenir au moins 2 caractères.',
            'telephone.regex' => 'Le téléphone doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'whatsapp.regex'  => 'Le numéro WhatsApp doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
        ];
    }
}
