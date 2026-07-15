<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // 認可は EnsureAdmin ミドルウェアで実施
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['procedure', 'policy', 'faq', 'manual', 'rule'])],
            'content' => ['required', 'string'],
        ];
    }
}
