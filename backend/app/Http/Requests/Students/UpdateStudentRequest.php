<?php

declare(strict_types=1);

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The record-level check lives in StudentPolicy::update, invoked by the
        // controller; this only screens the coarse capability.
        return $this->user()?->hasPermission('students.update') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'birth_place' => ['nullable', 'string', 'max:160'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'blood_group' => ['nullable', 'string', 'max:8'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],
            'previous_school' => ['nullable', 'string', 'max:160'],
        ];
    }
}
