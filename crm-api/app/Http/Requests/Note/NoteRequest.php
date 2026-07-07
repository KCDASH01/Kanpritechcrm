<?php

namespace App\Http\Requests\Note;

use Illuminate\Foundation\Http\FormRequest;

class NoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'notable_type' => ['required', 'in:lead,deal'],
            'notable_id'   => ['required', 'integer'],
            'content'      => ['required', 'string'],
            'is_pinned'    => ['boolean'],
        ];
    }
}
