<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Identity\Rules\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordRules::default()],
        ];
    }
}
