<?php

namespace App\Http\Requests\Geography;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('states', 'slug')->ignore($this->route('state')),
            ],
            'code' => [
                'sometimes', 'string', 'max:10',
                Rule::unique('states', 'code')->ignore($this->route('state')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
