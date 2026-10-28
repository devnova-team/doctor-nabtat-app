<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clinic_id' => $this->clinic_id,
            'patient' => [
                'id' => $this->patient->id,
                'name' => $this->patient->name,
                'file_number' => $this->patient->file_number,
            ],
            'doctor' => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'specialty' => $this->doctor->specialty,
            ],
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'status' => $this->status,
            'source' => $this->source,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
