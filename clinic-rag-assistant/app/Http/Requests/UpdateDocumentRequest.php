<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', Rule::in(['procedure', 'policy', 'faq', 'manual', 'rule'])],
            'content' => ['required', 'string'],
        ];
    }
}
