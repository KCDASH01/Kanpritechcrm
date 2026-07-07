<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:191'],
            'email'             => ['required', 'email', 'max:191', 'unique:users,email'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
            'organization_name' => ['nullable', 'string', 'max:191'],
            'timezone'          => ['nullable', 'string', 'max:64'],
        ];
    }
}
