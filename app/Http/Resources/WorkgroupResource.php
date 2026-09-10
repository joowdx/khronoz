<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the `Workgroup` interface in resources/js/types/index.d.ts (Task 6).
 *
 * `head` is present only when the controller eager-loads `head`; absent
 * (rather than null) means "not loaded", the same convention EmployeeResource
 * uses for `current_deployment`. The workgroups tree is built client-side from a
 * flat list keyed by `parent_id`, so this resource carries no nested
 * `children` — see task-6-brief.md's workgroups/index composition notes.
 *
 * `people_count` is the open-deployment headcount of this workgroup **and
 * everything under it** — WorkgroupController::index rolls its own withCount
 * aggregate up over the flat list before this resolves, because the number is
 * a link to `/employees?workgroup=…` and that filter expands over the subtree
 * (01-organization.md rule 4). `deployments_count` is every placement this
 * workgroup itself has ever held and is deliberately not rolled up; see
 * WorkgroupController::rollUpPeople. Both are aliased by WorkgroupController::index's
 * withCount and therefore present only there; every
 * other place this resource appears (the parent and workgroup pickers, a workgroup's
 * own edit form) omits the key rather than sending zero, so a missing count
 * can never read as "nobody is here". The list's own row type is `WorkgroupRow` in
 * resources/js/pages/workgroups/index.tsx — the same split AgencyResource uses for
 * `users_count`.
 *
 * @mixin \App\Models\Workgroup
 */
class WorkgroupResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'head_id' => $this->head_id,
            'head' => $this->whenLoaded('head', fn ($head) => EmployeeResource::make($head)->resolve()),
            'people_count' => $this->whenCounted('people'),
            'deployments_count' => $this->whenCounted('deployments'),
        ];
    }
}
