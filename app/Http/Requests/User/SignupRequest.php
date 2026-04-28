<?php

namespace App\Http\Requests\User;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SignupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:64'],
            'email'    => ['nullable', 'email', 'required_without:phone', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'required_without:email', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * Custom error messages (optional)
     */
    public function messages(): array
    {
        return [
            'name.required'     => 'Name is required',
            'name.string'       => 'Name must be a string',
            'name.max'          => 'Name must not exceed 64 characters',
            'email.required_without' => 'Email or phone is required',
            'email.email'       => 'Enter a valid email address',
            'email.unique'      => 'This email is already registered',
            'phone.required_without' => 'Phone or email is required',
            'phone.string'      => 'Phone number must be a string',
            'phone.unique'      => 'This phone number is already registered',
            'password.required' => 'Password is required',
            'password.min'      => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
        ];
    }
}
