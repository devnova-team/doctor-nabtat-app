<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Staff\StoreStaffRequest;
use App\Http\Requests\Api\Staff\UpdateStaffRequest;
use App\Http\Resources\Api\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clinicId = $request->user()?->clinic_id;
        abort_unless($clinicId, JsonResponse::HTTP_FORBIDDEN);

        return UserResource::collection(User::query()
            ->where('clinic_id', $clinicId)
            ->where('role', '<>', 'owner')
            ->orderBy('name')
            ->paginate(30))->response();
    }

    public function store(StoreStaffRequest $request, AuditLogService $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);
        $staff = User::query()->create([
            ...$request->validated(),
            'clinic_id' => $user->clinic_id,
            'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);

        $audit->record($user, 'staff.created', 'info', $request, ['staff_id' => $staff->id, 'role' => $staff->role]);

        return (new UserResource($staff))->response()->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function update(UpdateStaffRequest $request, int $staff, AuditLogService $audit, AuthService $auth): UserResource
    {
        $user = $request->user();
        abort_unless($user?->clinic_id, JsonResponse::HTTP_FORBIDDEN);

        $staffRecord = User::query()
            ->where('clinic_id', $user->clinic_id)
            ->where('role', '<>', 'owner')
            ->findOrFail($staff);
        $data = $request->validated();
        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        $staffRecord->update($data);
        if (array_key_exists('is_active', $data) || array_key_exists('role', $data) || array_key_exists('password', $data)) {
            $staffRecord->increment('token_version');
            Cache::put('auth:user-version:'.$staffRecord->id, (int) $staffRecord->fresh()->token_version, now()->addDays(30));
            $auth->revokeUserSessions($staffRecord->id);
        }
        if (array_key_exists('is_active', $data)) {
            if ($data['is_active']) {
                Cache::forget('auth:revoked-user:'.$staffRecord->id);
            } else {
                Cache::put('auth:revoked-user:'.$staffRecord->id, true, now()->addDays(30));
            }
        }
        $audit->record($user, 'staff.updated', 'info', $request, ['staff_id' => $staffRecord->id]);

        return new UserResource($staffRecord->refresh());
    }
}
