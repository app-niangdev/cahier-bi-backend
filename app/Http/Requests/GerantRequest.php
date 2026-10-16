<?php

namespace App\Http\Requests;

use App\Support\SenegalPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout et modification d'un gérant par le propriétaire. Le mot de passe saisi à l'ajout
 * est provisoire : le gérant le remplace à sa première connexion, après un code WhatsApp.
 * Le changement de boutique passe par le transfert, pas par cette requête.
 */
class GerantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isProprietaire() ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['phone_one', 'phone_two'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => SenegalPhone::clean($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $id = $this->route('id');
        $creation = $id === null;
        $required = $creation ? 'required' : 'sometimes';

        return [
            'boutique_id' => [$creation ? 'required' : 'prohibited', 'integer'],
            'first_name'  => [$required, 'required', 'string', 'min:2', 'max:100'],
            'last_name'   => [$required, 'required', 'string', 'min:2', 'max:100'],
            'phone_one'   => [
                $required, 'required', 'string', 'regex:' . SenegalPhone::PATTERN,
                Rule::unique('users', 'phone_one')->whereNull('deleted_at')->ignore($id),
            ],
            'phone_two'   => ['nullable', 'string', 'regex:' . SenegalPhone::PATTERN],
            'address'     => ['nullable', 'string', 'max:255'],
            'password'    => [$creation ? 'required' : 'prohibited', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'boutique_id.required' => 'Choisissez la boutique du gérant.',
            'boutique_id.prohibited' => 'Pour changer la boutique d\'un gérant, utilisez le transfert.',
            'first_name.required'  => 'Le prénom est obligatoire.',
            'first_name.min'       => 'Le prénom doit contenir au moins 2 caractères.',
            'last_name.required'   => 'Le nom est obligatoire.',
            'last_name.min'        => 'Le nom doit contenir au moins 2 caractères.',
            'phone_one.required'   => 'Le numéro de téléphone est obligatoire.',
            'phone_one.regex'      => 'Le numéro doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'phone_one.unique'     => 'Ce numéro est déjà utilisé par un autre compte.',
            'phone_two.regex'      => 'Le numéro secondaire doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'password.required'    => 'Le mot de passe provisoire est obligatoire.',
            'password.min'         => 'Le mot de passe provisoire doit contenir au moins 8 caractères.',
            'password.prohibited'  => 'Pour changer le mot de passe d\'un gérant, réinitialisez son accès.',
        ];
    }
}
