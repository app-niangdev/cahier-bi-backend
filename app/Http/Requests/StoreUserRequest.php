<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Models\Role;
use App\Support\SenegalPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un compte par l'administrateur. Le propriétaire ou le gérant se connecte avec son numéro
 * et le mot de passe provisoire fixé ici ; à sa première connexion il reçoit un code WhatsApp
 * puis choisit son propre mot de passe.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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
        $role = fn () => Role::find($this->input('role_id'))?->name;
        $isAdmin = fn () => $role() === RoleName::Admin->value;
        $isGerant = fn () => $role() === RoleName::Gerant->value;

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['required', 'string', 'max:255'],
            'username'   => ['nullable', 'string', 'max:255'],
            'email'      => [
                Rule::requiredIf($isAdmin), 'nullable', 'email', 'max:255',
                Rule::unique('users', 'email'),
            ],
            'phone_one'  => [
                'required', 'string', 'regex:' . SenegalPhone::PATTERN,
                Rule::unique('users', 'phone_one')->whereNull('deleted_at'),
            ],
            'phone_two'  => ['nullable', 'string', 'regex:' . SenegalPhone::PATTERN],
            'address'    => ['nullable', 'string', 'max:255'],
            'password'   => ['required', 'string', 'min:8'],
            'role_id'    => ['required', 'exists:roles,id'],
            // Propriétaire : son compte. Gérant : déduit de sa boutique.
            'tenant_id'  => [
                Rule::requiredIf(fn () => $role() === RoleName::Proprietaire->value),
                'nullable',
                'exists:tenants,id',
            ],
            'boutique_id' => [
                Rule::requiredIf($isGerant),
                'nullable',
                Rule::exists('boutiques', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Le prénom est obligatoire.',
            'first_name.max'      => 'Le prénom ne peut pas dépasser 255 caractères.',
            'last_name.required'  => 'Le nom est obligatoire.',
            'last_name.max'       => 'Le nom ne peut pas dépasser 255 caractères.',
            'email.required'      => "L'e-mail est obligatoire pour un administrateur.",
            'email.email'         => "L'adresse email n'est pas valide.",
            'email.unique'        => 'Cette adresse e-mail est déjà utilisée.',
            'phone_one.required'  => 'Le numéro de téléphone est obligatoire.',
            'phone_one.regex'     => 'Le numéro doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'phone_one.unique'    => 'Ce numéro est déjà utilisé par un autre compte.',
            'phone_two.regex'     => 'Le numéro secondaire doit comporter 9 chiffres et commencer par 70, 71, 75, 76, 77 ou 78.',
            'password.required'   => 'Le mot de passe provisoire est obligatoire.',
            'password.min'        => 'Le mot de passe provisoire doit contenir au moins 8 caractères.',
            'role_id.required'    => 'Le rôle est obligatoire.',
            'role_id.exists'      => 'Le rôle sélectionné est invalide.',
            'tenant_id.required'  => 'Le compte propriétaire est obligatoire.',
            'tenant_id.exists'    => 'Le compte propriétaire sélectionné est invalide.',
            'boutique_id.required' => 'La boutique est obligatoire pour un gérant.',
            'boutique_id.exists'  => 'La boutique sélectionnée est invalide.',
        ];
    }
}
