<?php

namespace App\Http\Requests\Mls;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMlsInfoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mls_directory_id' => ['required', 'integer', 'exists:mls_directories,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:255'],
            'public_websites_title' => ['nullable', 'string', 'max:1000'],
            'websites' => ['nullable', 'array'],
            'websites.*' => ['string', 'max:2048'],
            'info' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
