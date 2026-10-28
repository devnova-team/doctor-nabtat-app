<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Patients\RegisterPatientRequest;
use App\Http\Resources\Api\PatientResource;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        $filters = $request->validate([
            'phone' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'size:14'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if($filters === [], JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'Provide phone, national_id, or name to search.');

        $patients = Patient::query()
            ->where('clinic_id', $user->clinic_id)
            ->when(isset($filters['phone']), fn (Builder $query) => $query->where('phone', $filters['phone']))
            ->when(isset($filters['national_id']), fn (Builder $query) => $query->where('national_id', $filters['national_id']))
            ->when(isset($filters['name']), fn (Builder $query) => $query->where('name', 'like', '%'.$filters['name'].'%'))
            ->latest('id')
            ->paginate(20);

        return PatientResource::collection($patients)->response();
    }

    public function register(RegisterPatientRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user !== null && ! in_array($user->role, ['owner', 'admin', 'receptionist'], true)) {
            abort(JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->validated();
        $clinicId = $user?->clinic_id ?? $data['clinic_id'];
        $data['name'] = $data['full_name'] ?? $data['name'];
        unset($data['full_name'], $data['age']);
        do {
            $fileNumber = 'PAT-'.Str::upper(Str::random(8));
        } while (Patient::withTrashed()->where('clinic_id', $clinicId)->where('file_number', $fileNumber)->exists());

        $patient = Patient::query()->create([
            ...$data,
            'clinic_id' => $clinicId,
            'file_number' => $fileNumber,
        ]);

        return (new PatientResource($patient))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Request $request, int $patient): PatientResource
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        return new PatientResource(Patient::query()
            ->where('clinic_id', $user->clinic_id)
            ->findOrFail($patient));
    }
}
