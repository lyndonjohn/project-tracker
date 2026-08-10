<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;

class ProjectRestoreController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    /**
     * The route binds with trashed projects included, since a live one is exactly what
     * this endpoint does not operate on.
     */
    public function __invoke(Project $project): ProjectResource
    {
        return new ProjectResource(
            $this->projects->restore($project)->load('client'),
        );
    }
}
