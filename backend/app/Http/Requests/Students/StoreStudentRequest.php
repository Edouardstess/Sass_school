<?php

declare(strict_types=1);

namespace App\Http\Requests\Students;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Note what is *not* here: `school_id`, `matricule`, `status` and `user_id`.
 *
 * Validation is the allow-list. Anything a client sends that is not listed is
 * discarded by `validated()`, so a mass-assignment attempt never reaches the
 * service, let alone the model.
 */
class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('students.create') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
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
            'enrolled_on' => ['nullable', 'date'],

            'guardians' => ['nullable', 'array', 'max:5'],
            'guardians.*.guardian_id' => ['required', 'uuid'],
            'guardians.*.relationship' => ['required', 'string', 'max:40'],
            'guardians.*.is_primary' => ['boolean'],
            'guardians.*.is_financial_responsible' => ['boolean'],
            'guardians.*.can_pick_up' => ['boolean'],
        ];
    }
}
