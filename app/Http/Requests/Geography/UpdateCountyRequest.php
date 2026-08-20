<?php

namespace App\Http\Requests\Geography;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountyRequest extends FormRequest
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
        $stateId = $this->input('state_id', $this->route('county')?->state_id);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'state_id' => ['sometimes', 'uuid', 'exists:states,id'],
            'slug' => [
                'sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('counties', 'slug')
                    ->where(fn ($query) => $query->where('state_id', $stateId))
                    ->ignore($this->route('county')),
            ],
            'code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('counties', 'code')
                    ->where(fn ($query) => $query->where('state_id', $stateId))
                    ->ignore($this->route('county')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
