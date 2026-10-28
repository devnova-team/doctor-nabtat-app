<?php

namespace App\Http\Requests\Api\Appointments;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookAppointmentRequest extends FormRequest
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
        $clinicId = $this->user()?->clinic_id;

        return [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)],
            'doctor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('clinic_id', $clinicId)->where('role', 'doctor')->where('is_active', true)],
            'starts_at' => ['required', 'date', 'after:now'],
            'source' => ['sometimes', Rule::in(['online', 'phone', 'in_person'])],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
