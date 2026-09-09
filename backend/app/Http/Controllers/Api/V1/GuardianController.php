<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Student\Models\Guardian;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuardianResource;
use App\Http\Resources\StudentResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GuardianController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guardian::class);

        $guardians = Guardian::query()
            ->withCount('students')
            ->applySearch($request->query('search'), ['first_name', 'last_name', 'email', 'phone'])
            ->applySort($request->query('sort'), ['last_name', 'created_at'], 'last_name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ApiResponse::paginated($guardians, GuardianResource::class);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Guardian::class);

        $guardian = Guardian::query()->create($request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'phone_alt' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'national_id' => ['nullable', 'string', 'max:60'],
            'preferred_channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp', 'in_app'])],
        ]));

        $this->audit->created($guardian, "Created guardian {$guardian->full_name}");

        return ApiResponse::created(new GuardianResource($guardian), __('responses.created'));
    }

    public function show(Guardian $guardian): JsonResponse
    {
        $this->authorize('view', $guardian);

        return ApiResponse::success(
            new GuardianResource($guardian->load(['students', 'user:id,email,status']))
        );
    }

    public function update(Request $request, Guardian $guardian): JsonResponse
    {
        $this->authorize('update', $guardian);

        $before = $guardian->getOriginal();

        $guardian->fill($request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'phone_alt' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'national_id' => ['nullable', 'string', 'max:60'],
            'preferred_channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp', 'in_app'])],
        ]))->save();

        $this->audit->updated($guardian, $before);

        return ApiResponse::success(new GuardianResource($guardian), __('responses.updated'));
    }

    public function destroy(Guardian $guardian): JsonResponse
    {
        $this->authorize('delete', $guardian);

        $guardian->delete();
        $this->audit->deleted($guardian);

        return ApiResponse::noContent();
    }

    /** The children this guardian is responsible for. */
    public function students(Guardian $guardian): JsonResponse
    {
        $this->authorize('view', $guardian);

        return ApiResponse::success(
            StudentResource::collection($guardian->students()->with('enrollments.schoolClass')->get())
        );
    }
}
