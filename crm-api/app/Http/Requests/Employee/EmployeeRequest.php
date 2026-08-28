<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $userId   = $this->route('employee')?->id;

        return [
            'name'     => [$isCreate ? 'required' : 'sometimes', 'string', 'max:191'],
            'email'    => [
                $isCreate ? 'required' : 'sometimes',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Password-only updates (team "Reset Password") are valid without name/email.
            'password' => [
                $isCreate ? 'required' : 'required_without_all:name,email,phone,role,avatar',
                'nullable',
                'string',
                'min:8',
            ],
            'phone'    => ['nullable', 'string', 'max:30'],
            'role'     => ['nullable', 'in:admin,employee'],
            'avatar'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
