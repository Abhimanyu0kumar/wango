<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'email_or_phone' => ['required', 'string'],
            'password'       => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * Custom error messages (optional)
     */
    public function messages(): array
    {
        return [
            'email_or_phone.required' => 'Email or phone is required',
            'email_or_phone.string'   => 'Email or phone must be a valid string',
            'password.required'       => 'Password is required',
            'password.min'            => 'Password must be at least 8 characters',
        ];
    }
}
