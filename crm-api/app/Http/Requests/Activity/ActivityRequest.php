<?php

namespace App\Http\Requests\Activity;

use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'subject_type' => ['nullable', 'in:lead,deal'],
            'subject_id'   => ['nullable', 'integer'],
            'type'         => ['required', 'in:call,email,meeting,task,note,deadline,whatsapp'],
            'title'        => ['required', 'string', 'max:191'],
            'description'  => ['nullable', 'string'],
            'due_at'       => ['nullable', 'date'],
            'priority'     => ['nullable', 'in:low,medium,high'],
            'assigned_to'  => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
