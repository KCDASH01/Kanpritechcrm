<?php

namespace App\Http\Controllers\SalesTarget;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\ManualTargetEntry;
use App\Models\TargetAssignment;
use App\Models\TargetDefinition;
use App\Models\TargetManagementAudit;
use App\Models\User;
use App\Services\DynamicTargetMetricService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DynamicTargetController extends Controller
{
    private const UNITS = ['currency', 'number', 'percentage', 'leads', 'deals', 'calls', 'meetings', 'projects', 'hours', 'days', 'custom'];

    private const PERIODS = ['monthly', 'quarterly', 'yearly', 'custom'];

    public function __construct(private readonly DynamicTargetMetricService $metrics) {}

    public function definitions(Request $request): JsonResponse
    {
        $definitions = TargetDefinition::query()->where('organization_id', $this->orgId($request))
            ->with('departments:id,name')->orderByDesc('is_active')->orderBy('name')->get();

        return response()->json(['data' => $definitions, 'meta' => [
            'units' => self::UNITS,
            'metric_sources' => DynamicTargetMetricService::SOURCES,
        ]]);
    }

    public function storeDefinition(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $this->definitionData($request);
        $definition = DB::transaction(function () use ($request, $data) {
            $departmentIds = $data['department_ids'] ?? [];
            unset($data['department_ids']);
            $definition = TargetDefinition::create($data + [
                'organization_id' => $this->orgId($request),
                'created_by' => $request->user()->id,
            ]);
            $definition->departments()->sync($departmentIds);
            $this->audit($request, 'definition', $definition->id, 'created', null, $definition->toArray());

            return $definition;
        });

        return response()->json(['data' => $definition->load('departments:id,name')], 201);
    }

    public function updateDefinition(Request $request, TargetDefinition $targetDefinition): JsonResponse
    {
        $this->authorizeDefinition($request, $targetDefinition);
        $this->authorizeManagement($request);
        $data = $this->definitionData($request, $targetDefinition);
        if ($targetDefinition->assignments()->exists() && $data['code'] !== $targetDefinition->code) {
            throw ValidationException::withMessages(['code' => 'The stable code cannot change after assignments exist.']);
        }
        $before = $targetDefinition->load('departments:id')->toArray();
        DB::transaction(function () use ($request, $targetDefinition, $data, $before) {
            $departmentIds = $data['department_ids'] ?? [];
            unset($data['department_ids']);
            $targetDefinition->update($data + ['updated_by' => $request->user()->id]);
            $targetDefinition->departments()->sync($departmentIds);
            $this->audit($request, 'definition', $targetDefinition->id, 'updated', $before, $targetDefinition->fresh()->toArray());
        });

        return response()->json(['data' => $targetDefinition->fresh()->load('departments:id,name')]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'upcoming', 'completed', 'archived'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = TargetAssignment::query()->where('organization_id', $this->orgId($request))
            ->with(['definition:id,name,code,is_active', 'user:id,name', 'user.organization:id,timezone', 'department:id,name', 'entries.user:id,name']);
        if (! $this->canManage($request)) {
            $departmentIds = $request->user()->departments()->pluck('departments.id');
            $query->where(function (Builder $visible) use ($request, $departmentIds) {
                $visible->where('user_id', $request->user()->id)
                    ->orWhere(function (Builder $shared) use ($departmentIds) {
                        $shared->where('scope_type', 'department_shared')->whereIn('department_id', $departmentIds);
                    });
            });
        } else {
            if (! empty($data['user_id'])) {
                $selectedUser = User::query()->where('organization_id', $this->orgId($request))->findOrFail($data['user_id']);
                $selectedDepartments = $selectedUser->departments()->pluck('departments.id');
                $query->where(function (Builder $visible) use ($selectedUser, $selectedDepartments) {
                    $visible->where('user_id', $selectedUser->id)
                        ->orWhere(function (Builder $shared) use ($selectedDepartments) {
                            $shared->where('scope_type', 'department_shared')->whereIn('department_id', $selectedDepartments);
                        });
                });
            }
            $query->when($data['department_id'] ?? null, fn (Builder $q, $id) => $q->where('department_id', $id));
        }
        $query->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('period_end', '>=', $from))
            ->when($data['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('period_start', '<=', $to));
        $assignments = $query->orderByDesc('period_start')->orderByDesc('id')->get();
        $rows = $assignments->map(fn (TargetAssignment $assignment) => $this->metrics->progress($assignment, $request->user()));
        if (! empty($data['status'])) {
            $rows = $rows->where('status', $data['status'])->values();
        }

        return response()->json(['data' => $rows]);
    }

    public function storeAssignments(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['employee', 'department_shared'])],
            'user_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'period_type' => ['required', Rule::in(self::PERIODS)],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*.target_definition_id' => ['required', 'integer', 'distinct'],
            'targets.*.target_value' => ['required', 'numeric', 'min:0'],
        ]);
        [$user, $department] = $this->validateScope($request, $data);
        $start = Carbon::parse($data['period_start'])->toDateString();
        $end = Carbon::parse($data['period_end'])->toDateString();
        $created = DB::transaction(function () use ($request, $data, $user, $department, $start, $end) {
            DB::table('organizations')->where('id', $this->orgId($request))->lockForUpdate()->first();

            return collect($data['targets'])->map(function (array $row) use ($request, $data, $user, $department, $start, $end) {
                $definition = TargetDefinition::query()->where('organization_id', $this->orgId($request))
                    ->where('is_active', true)->with('departments:id')->lockForUpdate()->findOrFail($row['target_definition_id']);
                $this->validateApplicability($definition, $user, $department);
                if ($definition->unit_type === 'percentage' && (float) $row['target_value'] > 100) {
                    throw ValidationException::withMessages(['targets' => "{$definition->name} must be between 0 and 100%."]);
                }
                $this->rejectOverlap($request, $definition->id, $data['scope_type'], $user?->id, $department?->id, $start, $end);
                $assignment = TargetAssignment::create([
                    'organization_id' => $this->orgId($request), 'target_definition_id' => $definition->id,
                    'scope_type' => $data['scope_type'], 'user_id' => $user?->id, 'department_id' => $department?->id,
                    'period_type' => $data['period_type'], 'period_start' => $start, 'period_end' => $end,
                    'target_value' => $row['target_value'], 'status' => 'active',
                    'definition_code' => $definition->code, 'definition_name' => $definition->name,
                    'unit_type' => $definition->unit_type, 'unit_label' => $definition->unit_label,
                    'metric_source' => $definition->metric_source, 'direction' => $definition->direction,
                    'assigned_by' => $request->user()->id,
                ]);
                $this->audit($request, 'assignment', $assignment->id, 'created', null, $assignment->toArray());

                return $assignment;
            });
        });

        return response()->json(['data' => $created], 201);
    }

    public function updateAssignment(Request $request, TargetAssignment $targetAssignment): JsonResponse
    {
        $this->authorizeAssignment($request, $targetAssignment);
        $this->authorizeManagement($request);
        $data = $request->validate([
            'target_value' => ['required', 'numeric', 'min:0'],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'period_type' => ['required', Rule::in(self::PERIODS)],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);
        if ($targetAssignment->unit_type === 'percentage' && (float) $data['target_value'] > 100) {
            throw ValidationException::withMessages(['target_value' => 'Percentage targets must be between 0 and 100%.']);
        }
        $start = Carbon::parse($data['period_start'])->toDateString();
        $end = Carbon::parse($data['period_end'])->toDateString();
        DB::transaction(function () use ($request, $targetAssignment, $data, $start, $end) {
            DB::table('organizations')->where('id', $this->orgId($request))->lockForUpdate()->first();
            TargetDefinition::query()->whereKey($targetAssignment->target_definition_id)->lockForUpdate()->firstOrFail();
            $this->rejectOverlap($request, $targetAssignment->target_definition_id, $targetAssignment->scope_type, $targetAssignment->user_id, $targetAssignment->department_id, $start, $end, $targetAssignment->id);
            $before = $targetAssignment->toArray();
            $targetAssignment->update($data + ['updated_by' => $request->user()->id]);
            $this->audit($request, 'assignment', $targetAssignment->id, 'updated', $before, $targetAssignment->fresh()->toArray());
        });

        return response()->json(['data' => $targetAssignment->fresh()]);
    }

    public function storeManualEntry(Request $request, TargetAssignment $targetAssignment): JsonResponse
    {
        $this->authorizeAssignment($request, $targetAssignment);
        $this->authorizeManagement($request);
        if ($targetAssignment->metric_source !== 'manual') {
            throw ValidationException::withMessages(['target_assignment_id' => 'Actual values for this target are calculated automatically.']);
        }
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'], 'entry_date' => ['required', 'date'],
            'actual_value' => ['required', 'numeric'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $entryDate = Carbon::parse($data['entry_date'])->toDateString();
        if ($entryDate < $targetAssignment->period_start->toDateString() || $entryDate > $targetAssignment->period_end->toDateString()) {
            throw ValidationException::withMessages(['entry_date' => 'Entry date must be within the target period.']);
        }
        $user = null;
        if (! empty($data['user_id'])) {
            $user = User::query()->where('organization_id', $this->orgId($request))->findOrFail($data['user_id']);
            if ($targetAssignment->scope_type === 'employee' && (int) $targetAssignment->user_id !== (int) $user->id) {
                throw ValidationException::withMessages(['user_id' => 'This employee is not part of the target scope.']);
            }
            if ($targetAssignment->scope_type === 'department_shared' && ! $user->departments()->whereKey($targetAssignment->department_id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This employee is not in the target department.']);
            }
        }
        $subjectKey = $user ? 'user:'.$user->id : 'scope';
        $entry = ManualTargetEntry::query()->firstOrNew([
            'target_assignment_id' => $targetAssignment->id, 'subject_key' => $subjectKey, 'entry_date' => $entryDate,
        ]);
        $before = $entry->exists ? $entry->toArray() : null;
        $entry->fill([
            'organization_id' => $this->orgId($request), 'user_id' => $user?->id,
            'actual_value' => $data['actual_value'], 'notes' => $data['notes'] ?? null,
            'updated_by' => $request->user()->id,
        ]);
        if (! $entry->exists) {
            $entry->created_by = $request->user()->id;
        }
        $entry->save();
        $this->audit($request, 'manual_entry', $entry->id, $entry->wasRecentlyCreated ? 'created' : 'updated', $before, $entry->toArray());

        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function definitionData(Request $request, ?TargetDefinition $definition = null): array
    {
        $orgId = $this->orgId($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_]+$/', Rule::unique('target_definitions', 'code')->where('organization_id', $orgId)->ignore($definition?->id)],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'unit_type' => ['required', Rule::in(self::UNITS)], 'unit_label' => ['nullable', 'string', 'max:32'],
            'metric_source' => ['required', Rule::in(array_keys(DynamicTargetMetricService::SOURCES))],
            'direction' => ['required', Rule::in(['higher', 'lower'])], 'is_active' => ['required', 'boolean'],
            'department_ids' => ['present', 'array'],
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
        ]);

        $compatibleUnits = [
            'revenue_generated' => ['currency'],
            'collections_received' => ['currency'],
            'deals_closed' => ['deals', 'number'],
            'leads_assigned' => ['leads', 'number'],
            'lead_conversion_ratio' => ['percentage'],
        ];
        if (isset($compatibleUnits[$data['metric_source']]) && ! in_array($data['unit_type'], $compatibleUnits[$data['metric_source']], true)) {
            throw ValidationException::withMessages(['unit_type' => 'The selected unit is not compatible with this automatic metric source.']);
        }
        if ($data['unit_type'] === 'custom' && blank($data['unit_label'] ?? null)) {
            throw ValidationException::withMessages(['unit_label' => 'A label is required for a custom unit.']);
        }

        return $data;
    }

    /** @return array{0:?User,1:?Department} */
    private function validateScope(Request $request, array $data): array
    {
        $user = ! empty($data['user_id']) ? User::query()->where('organization_id', $this->orgId($request))->where('is_active', true)->where('role', '!=', 'owner')->findOrFail($data['user_id']) : null;
        $department = ! empty($data['department_id']) ? Department::query()->where('organization_id', $this->orgId($request))->findOrFail($data['department_id']) : null;
        if ($data['scope_type'] === 'employee' && ! $user) {
            throw ValidationException::withMessages(['user_id' => 'Select an employee for an individual target.']);
        }
        if ($data['scope_type'] === 'department_shared' && ! $department) {
            throw ValidationException::withMessages(['department_id' => 'Select a department for a shared target.']);
        }
        if ($user && $department && ! $user->departments()->whereKey($department->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'The employee does not belong to the selected department.']);
        }

        return [$user, $department];
    }

    private function validateApplicability(TargetDefinition $definition, ?User $user, ?Department $department): void
    {
        $allowed = $definition->departments->pluck('id');
        if ($allowed->isEmpty()) {
            return;
        }
        $valid = $department ? $allowed->contains($department->id) : (bool) $user?->departments()->whereIn('departments.id', $allowed)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['target_definition_id' => "{$definition->name} is not applicable to this department."]);
        }
    }

    private function rejectOverlap(Request $request, int $definitionId, string $scope, ?int $userId, ?int $departmentId, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlap = TargetAssignment::query()->where('organization_id', $this->orgId($request))
            ->where('target_definition_id', $definitionId)->where('scope_type', $scope)->where('status', 'active')
            ->when($userId, fn (Builder $q) => $q->where('user_id', $userId), fn (Builder $q) => $q->whereNull('user_id'))
            ->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId), fn (Builder $q) => $q->whereNull('department_id'))
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['targets' => 'The same target type already overlaps this scope and period.']);
        }
    }

    private function audit(Request $request, string $entityType, int $entityId, string $action, ?array $before, ?array $after): void
    {
        TargetManagementAudit::create([
            'organization_id' => $this->orgId($request), 'entity_type' => $entityType, 'entity_id' => $entityId,
            'action' => $action, 'before_values' => $before, 'after_values' => $after, 'actor_id' => $request->user()->id,
        ]);
    }

    private function orgId(Request $request): int
    {
        return (int) $request->user()->organization_id;
    }

    private function canManage(Request $request): bool
    {
        return in_array($request->user()->role, ['owner', 'admin'], true);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($this->canManage($request), 403);
    }

    private function authorizeDefinition(Request $request, TargetDefinition $definition): void
    {
        abort_unless((int) $definition->organization_id === $this->orgId($request), 404);
    }

    private function authorizeAssignment(Request $request, TargetAssignment $assignment): void
    {
        abort_unless((int) $assignment->organization_id === $this->orgId($request), 404);
    }
}
