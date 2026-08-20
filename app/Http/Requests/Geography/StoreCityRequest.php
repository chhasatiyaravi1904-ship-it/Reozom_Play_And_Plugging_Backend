<?php

namespace App\Http\Requests\Geography;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'county_id' => ['required', 'uuid', 'exists:counties,id'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('cities', 'slug')->where(fn ($query) => $query->where('county_id', $this->input('county_id'))),
            ],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('cities', 'code')->where(fn ($query) => $query->where('county_id', $this->input('county_id'))),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
