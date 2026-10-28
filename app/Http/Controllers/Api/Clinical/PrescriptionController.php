<?php

namespace App\Http\Controllers\Api\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Clinical\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionPrintResource;
use App\Models\Consultation;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PrescriptionController extends Controller
{
    public function store(StorePrescriptionRequest $request, int $consultation): JsonResponse
    {
        $user = $request->user();
        $consultationRecord = Consultation::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('doctor_id', $user->id)
            ->findOrFail($consultation);
        $data = $request->validated();

        $prescription = DB::transaction(function () use ($consultationRecord, $data, $user): Prescription {
            $prescription = $consultationRecord->prescriptions()->create([
                'doctor_id' => $user->id,
                'issued_at' => now(),
                'prescription' => $data['prescription'],
            ]);

            return $prescription;
        });

        return response()->json([
            'data' => [
                'id' => $prescription->id,
                'consultation_id' => $prescription->consultation_id,
                'doctor_id' => $prescription->doctor_id,
                'issued_at' => $prescription->issued_at,
                'prescription' => $prescription->prescription,
            ],
        ], JsonResponse::HTTP_CREATED);
    }

    public function print(int $prescription): PrescriptionPrintResource
    {
        $user = request()->user();

        $prescriptionRecord = Prescription::query()
            ->with([
                'doctor',
                'consultation.clinic',
                'consultation.patient',
            ])
            ->whereHas('consultation', function ($query) use ($user): void {
                $query->where('clinic_id', $user->clinic_id);
            })
            ->findOrFail($prescription);

        return new PrescriptionPrintResource($prescriptionRecord);
    }
}
