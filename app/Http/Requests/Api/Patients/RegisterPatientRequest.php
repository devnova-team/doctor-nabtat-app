<?php

namespace App\Http\Requests\Api\Patients;

use App\Services\EgyptianNationalIdParser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterPatientRequest extends FormRequest
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
        $clinicId = $this->user()?->clinic_id ?? $this->input('clinic_id');

        return [
            'clinic_id' => [$this->user()?->clinic_id ? 'nullable' : 'required', 'integer', 'exists:clinics,id'],
            'full_name' => ['required_without:name', 'string', 'max:255'],
            'name' => ['required_without:full_name', 'string', 'max:255'],
            'national_id' => [
                'required',
                'string',
                'regex:/^\d{14}$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && app(EgyptianNationalIdParser::class)->parse($value) === null) {
                        $fail('The national ID is invalid.');
                    }
                },
                Rule::unique('patients', 'national_id')->where('clinic_id', $clinicId),
            ],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'age' => ['sometimes', 'integer', 'min:0', 'max:150'],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'blood_type' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'chronic_diseases' => ['nullable', 'string', 'max:10000'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $identity = app(EgyptianNationalIdParser::class)->parse((string) $this->input('national_id'));
            if ($identity === null) {
                return;
            }

            if (Carbon::parse($this->input('date_of_birth'))->toDateString() !== $identity['date_of_birth']) {
                $validator->errors()->add('date_of_birth', 'The date of birth must match the national ID.');
            }

            if ($this->input('gender') !== $identity['gender']) {
                $validator->errors()->add('gender', 'The gender must match the national ID.');
            }

            if ($this->filled('age') && (int) $this->input('age') !== Carbon::parse($identity['date_of_birth'])->age) {
                $validator->errors()->add('age', 'The age must match the date of birth.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'errors' => collect($validator->errors()->messages())
                ->flatMap(fn (array $messages, string $field): array => array_map(
                    fn (string $message): array => ['field' => $field, 'message' => $message],
                    $messages,
                ))
                ->values(),
        ], 422));
    }
}
