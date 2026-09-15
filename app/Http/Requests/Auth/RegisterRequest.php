<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
     * Public self-registration accepts agent/seller/buyer as the account
     * type — never admin, which stays provisioned separately. Agent
     * accounts additionally require admin approval before they can log in
     * (handled in AuthController::register), but that's an activation
     * concern, not a validation one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'userType' => ['required', Rule::in(['agent', 'seller', 'buyer'])],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'streetAddress' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:10'],
            'zip' => ['required', 'string', 'max:10'],
            'password' => ['required', 'same:passwordConfirmation', Password::defaults()],
            'passwordConfirmation' => ['required', 'string'],
            'packageId' => ['nullable', 'string', Rule::exists('packages', 'id')->where('is_active', true)],
        ];
    }
}
