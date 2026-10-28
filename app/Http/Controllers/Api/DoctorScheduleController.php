<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DoctorSchedules\StoreDoctorScheduleRequest;
use App\Http\Resources\Api\DoctorScheduleResource;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorScheduleController extends Controller
{
    public function index(Request $request, int $doctor): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        $doctorRecord = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('role', 'doctor')
            ->findOrFail($doctor);

        return DoctorScheduleResource::collection($doctorRecord->schedules()->orderBy('weekday')->orderBy('opens_at')->get())
            ->response();
    }

    public function store(StoreDoctorScheduleRequest $request, int $doctor): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        if (! in_array($user->role, ['owner', 'admin'], true) && ! ($user->role === 'doctor' && $user->id === $doctor)) {
            abort(JsonResponse::HTTP_FORBIDDEN);
        }

        $doctorRecord = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('role', 'doctor')
            ->findOrFail($doctor);
        $data = $request->validated();
        $opensAt = $data['opens_at'].':00';
        $closesAt = $data['closes_at'].':00';

        $overlappingSchedule = DoctorSchedule::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('doctor_id', $doctorRecord->id)
            ->where('weekday', $data['weekday'])
            ->where('is_active', true)
            ->where('opens_at', '<', $closesAt)
            ->where('closes_at', '>', $opensAt)
            ->where('opens_at', '<>', $opensAt)
            ->exists();

        if ($overlappingSchedule) {
            return response()->json(['message' => 'Doctor schedules cannot overlap on the same weekday.'], JsonResponse::HTTP_CONFLICT);
        }

        $schedule = DoctorSchedule::query()->updateOrCreate(
            [
                'doctor_id' => $doctorRecord->id,
                'weekday' => $data['weekday'],
                'opens_at' => $opensAt,
            ],
            [
                'clinic_id' => $user->clinic_id,
                'closes_at' => $closesAt,
                'slot_duration_minutes' => $data['slot_duration_minutes'] ?? 30,
                'is_active' => $data['is_active'] ?? true,
            ],
        );

        return (new DoctorScheduleResource($schedule))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
