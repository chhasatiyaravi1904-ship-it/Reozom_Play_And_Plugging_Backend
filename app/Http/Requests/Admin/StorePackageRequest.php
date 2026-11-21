<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the website feature lists and drop empty rows before validating.
     */
    protected function prepareForValidation(): void
    {
        foreach (['included_features', 'excluded_features'] as $key) {
            if (is_array($this->input($key))) {
                $items = array_map(fn ($item) => is_string($item) ? trim($item) : $item, $this->input($key));
                $this->merge([$key => array_values(array_filter($items, fn ($item) => $item !== '' && $item !== null))]);
            }
        }
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
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:packages,slug'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'min:0', new \App\Rules\StripeChargeablePrice()],
            'duration_days' => ['required', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'max_listing_processes' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            // Website card text: what the plan includes (✓) and doesn't include (✕), in display order
            'included_features' => ['nullable', 'array', 'max:20'],
            'included_features.*' => ['required', 'string', 'max:150', 'distinct'],
            'excluded_features' => ['nullable', 'array', 'max:20'],
            'excluded_features.*' => ['required', 'string', 'max:150', 'distinct'],
        ];
    }
}
