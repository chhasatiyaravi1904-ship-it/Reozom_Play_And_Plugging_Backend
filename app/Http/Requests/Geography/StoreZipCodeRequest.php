<?php

namespace App\Http\Requests\Geography;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\City;
use App\Models\County;
use App\Models\State;

class StoreZipCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Middleware handles auth (permission:manage-zip-codes)
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:10', 'unique:zip_codes,code'],
            'state_id' => ['required', 'uuid', 'exists:states,id'],
            'county_id' => ['required', 'uuid', 'exists:counties,id'],
            'city_id' => ['required', 'uuid', 'exists:cities,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->has(['state_id', 'county_id', 'city_id'])) {
                // Ensure geographical hierarchy is correct
                $county = County::find($this->county_id);
                $city = City::find($this->city_id);

                if ($county && $county->state_id !== $this->state_id) {
                    $validator->errors()->add('county_id', 'The selected county does not belong to the selected state.');
                }

                if ($city && $city->county_id !== $this->county_id) {
                    $validator->errors()->add('city_id', 'The selected city does not belong to the selected county.');
                }
            }
        });
    }
}
