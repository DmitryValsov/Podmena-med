<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],

            // пароль — как и было, через rules трэйта
            'password'        => $this->passwordRules(),

            // наши доп. поля
            'standart_hours'  => ['nullable', 'string', 'max:50'],
            'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
        ])->validate();

        return User::create([
            'name'           => $input['name'],
            'email'          => $input['email'],
            'standart_hours' => $input['standart_hours'] ?? null,
            'department_id'  => $input['department_id'] ?? null,

            // ВАЖНО: пароль в БД всегда в хэше
            'password'       => Hash::make($input['password']),
        ]);
    }
}
