<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexProjectRequest;
use App\Http\Requests\Api\V1\StoreProjectRequest;
use App\Http\Requests\Api\V1\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Thin by design: the route authorizes, the Form Request validates, ProjectService holds
 * the logic the Livewire pages run too, and ProjectResource shapes what comes back.
 */
class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    /** @return AnonymousResourceCollection<int, ProjectResource> */
    public function index(IndexProjectRequest $request): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            Project::query()
                ->listing($request->filters())
                ->paginate($request->perPage())
                ->withQueryString(),
        );
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->load('client'));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projects->create($request->validated());

        return (new ProjectResource($project->load('client')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $validated = $request->validated();

        $project = $this->projects->updateDetails(
            $project,
            Arr::except($validated, 'version'),
            $validated['version'],
        );

        return new ProjectResource($project->load('client'));
    }

    public function destroy(Request $request, Project $project): Response
    {
        $this->projects->delete($project, $request->user());

        return response()->noContent();
    }
}
