<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DoctorSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;

class AppointmentSchedulingService
{
    /**
     * @return array<int, array{starts_at: string, ends_at: string}>
     */
    public function availableSlots(User $doctor, string $date): array
    {
        $day = CarbonImmutable::parse($date)->dayOfWeekIso;
        $schedules = DoctorSchedule::query()
            ->where('clinic_id', $doctor->clinic_id)
            ->where('doctor_id', $doctor->id)
            ->where('weekday', $day)
            ->where('is_active', true)
            ->orderBy('opens_at')
            ->get();

        $appointments = Appointment::query()
            ->where('clinic_id', $doctor->clinic_id)
            ->where('doctor_id', $doctor->id)
            ->whereDate('starts_at', $date)
            ->whereIn('status', ['pending', 'confirmed', 'attended'])
            ->get(['starts_at', 'ends_at']);

        $slots = [];
        $today = now()->toDateString() === $date;

        foreach ($schedules as $schedule) {
            $cursor = CarbonImmutable::parse($date.' '.$schedule->opens_at);
            $closingTime = CarbonImmutable::parse($date.' '.$schedule->closes_at);

            while ($cursor->addMinutes($schedule->slot_duration_minutes)->lessThanOrEqualTo($closingTime)) {
                $slotEnd = $cursor->addMinutes($schedule->slot_duration_minutes);
                $isPast = $today && $cursor->lessThanOrEqualTo(now());
                $isBooked = $appointments->contains(fn (Appointment $appointment): bool => $cursor->lessThan($appointment->ends_at) && $slotEnd->greaterThan($appointment->starts_at));

                if (! $isPast && ! $isBooked) {
                    $slots[] = [
                        'starts_at' => $cursor->toDateTimeString(),
                        'ends_at' => $slotEnd->toDateTimeString(),
                    ];
                }

                $cursor = $slotEnd;
            }
        }

        return $slots;
    }
}
