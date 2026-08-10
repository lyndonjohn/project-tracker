<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProjectStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Carbon\CarbonImmutable;

/**
 * Its own endpoint because a status change also stamps started_at or completed_at and
 * carries its own permission, which the details update does not.
 */
class ProjectStatusController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    /**
     * The Project parameter is what makes the route bind one: implicit binding reads the
     * signature here, and without it the request's rules() would be handed a raw id.
     */
    public function __invoke(UpdateProjectStatusRequest $request, Project $project): ProjectResource
    {
        $validated = $request->validated();

        $project = $this->projects->updateStatus(
            $project,
            $request->statusEnum(),
            CarbonImmutable::parse($validated['changed_at']),
            $validated['version'],
        );

        return new ProjectResource($project->load('client'));
    }
}
