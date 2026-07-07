<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->route('employee')?->id;

        return [
            'name'     => ['required', 'string', 'max:191'],
            'email'    => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'string', 'min:8'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'role'     => ['nullable', 'in:admin,employee'],
            'avatar'   => ['nullable', 'string', 'max:500'],
        ];
    }
}
