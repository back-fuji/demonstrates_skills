<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:1', 'max:1000'],
            'session_id' => ['nullable', 'string', 'size:26'], // ULID
        ];
    }
}
