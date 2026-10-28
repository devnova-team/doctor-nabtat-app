<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Appointments\BookAppointmentRequest;
use App\Http\Requests\Api\Appointments\UpdateAppointmentStatusRequest;
use App\Http\Resources\Api\AppointmentResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    /** @var array<string, array{label: string, color: string}> */
    private const STATUS_METADATA = [
        'pending' => ['label' => 'Pending', 'color' => 'amber'],
        'confirmed' => ['label' => 'Confirmed', 'color' => 'blue'],
        'attended' => ['label' => 'Attended', 'color' => 'green'],
        'cancelled' => ['label' => 'Cancelled', 'color' => 'red'],
    ];

    public function availableSlots(Request $request, int $doctor, AppointmentSchedulingService $scheduling): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        $doctorRecord = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('role', 'doctor')
            ->where('is_active', true)
            ->findOrFail($doctor);

        return response()->json([
            'data' => [
                'doctor_id' => $doctorRecord->id,
                'date' => $data['date'],
                'slots' => $scheduling->availableSlots($doctorRecord, $data['date']),
            ],
        ]);
    }

    public function store(BookAppointmentRequest $request, AppointmentSchedulingService $scheduling): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);
        $data = $request->validated();

        if ($user->role === 'doctor' && (int) $data['doctor_id'] !== $user->id) {
            abort(JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $result = DB::transaction(function () use ($data, $user, $scheduling): ?Appointment {
                $doctor = User::query()
                    ->where('clinic_id', $user->clinic_id)
                    ->where('role', 'doctor')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->findOrFail($data['doctor_id']);

                $patient = Patient::query()
                    ->where('clinic_id', $user->clinic_id)
                    ->findOrFail($data['patient_id']);

                $startsAt = CarbonImmutable::parse($data['starts_at']);
                $startString = $startsAt->toDateTimeString();
                $slot = collect($scheduling->availableSlots($doctor, $startsAt->toDateString()))
                    ->firstWhere('starts_at', $startString);

                if ($slot === null) {
                    return null;
                }

                return Appointment::query()->create([
                    'clinic_id' => $user->clinic_id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'starts_at' => $slot['starts_at'],
                    'ends_at' => $slot['ends_at'],
                    'status' => 'pending',
                    'source' => $data['source'] ?? 'in_person',
                    'reason' => $data['reason'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by_user_id' => $user->id,
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                return response()->json(['message' => 'The selected appointment slot is no longer available.'], JsonResponse::HTTP_CONFLICT);
            }

            throw $exception;
        }

        if ($result === null) {
            return response()->json(['message' => 'The selected appointment slot is no longer available.'], JsonResponse::HTTP_CONFLICT);
        }

        return (new AppointmentResource($result->load(['patient', 'doctor'])))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function updateStatus(UpdateAppointmentStatusRequest $request, int $appointment): AppointmentResource|JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);
        $data = $request->validated();

        $appointmentRecord = DB::transaction(function () use ($data, $user, $appointment): ?Appointment {
            $record = Appointment::query()
                ->where('clinic_id', $user->clinic_id)
                ->lockForUpdate()
                ->findOrFail($appointment);

            if ($user->role === 'doctor' && $record->doctor_id !== $user->id) {
                abort(JsonResponse::HTTP_FORBIDDEN);
            }

            $transitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['attended', 'cancelled'],
                'attended' => [],
                'cancelled' => [],
            ];

            if ($record->status !== $data['status'] && ! in_array($data['status'], $transitions[$record->status] ?? [], true)) {
                return null;
            }

            $record->update(['status' => $data['status']]);

            return $record->load(['patient', 'doctor']);
        });

        if ($appointmentRecord === null) {
            return response()->json(['message' => 'The requested appointment status transition is not allowed.'], JsonResponse::HTTP_CONFLICT);
        }

        return new AppointmentResource($appointmentRecord);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'doctor_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,confirmed,attended,cancelled'],
        ]);

        $appointments = Appointment::query()
            ->with(['patient', 'doctor'])
            ->where('clinic_id', $user->clinic_id)
            ->when(isset($filters['date']), fn (Builder $query) => $query->whereDate('starts_at', $filters['date']))
            ->when(isset($filters['doctor_id']), fn (Builder $query) => $query->where('doctor_id', $filters['doctor_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($user->role === 'doctor', fn (Builder $query) => $query->where('doctor_id', $user->id))
            ->orderBy('starts_at')
            ->paginate(30);

        return AppointmentResource::collection($appointments)->response();
    }

    public function statuses(): JsonResponse
    {
        return response()->json(['data' => collect(self::STATUS_METADATA)->map(
            fn (array $metadata, string $status): array => ['key' => $status, ...$metadata],
        )->values()]);
    }
}
