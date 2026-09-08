<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // No complexity rules on *login*: the password either matches the
            // stored hash or it does not, and rejecting a long password here
            // would only tell an attacker about the policy.
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim(mb_strtolower($this->input('email')))]);
        }
    }
}
