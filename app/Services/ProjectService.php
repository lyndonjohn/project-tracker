<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Exceptions\StaleProjectException;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Receives data already validated by ProjectRules, so nothing here re-checks field shapes.
 */
class ProjectService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Project
    {
        Gate::authorize('projects.create');

        return DB::transaction(function () use ($data): Project {
            $client = $this->resolveClient($data['client_name']);

            return $client->projects()->create(Arr::except($data, 'client_name'));
        });
    }

    /**
     * Updates everything except the status, which moves through updateStatus().
     *
     * @param  array<string, mixed>  $data
     *
     * @throws StaleProjectException
     */
    public function updateDetails(Project $project, array $data, int $expectedVersion): Project
    {
        Gate::authorize('projects.edit-details');

        return DB::transaction(function () use ($project, $data, $expectedVersion): Project {
            $values = Arr::except($data, 'client_name');

            if (isset($data['client_name'])) {
                $values['client_id'] = $this->resolveClient($data['client_name'])->id;
            }

            return $this->updateGuardedByVersion($project, $values, $expectedVersion);
        });
    }

    /**
     * @throws StaleProjectException
     */
    public function updateStatus(Project $project, ProjectStatus $status, ?CarbonImmutable $changedAt, int $expectedVersion): Project
    {
        Gate::authorize('projects.update-status');

        $changedAt ??= CarbonImmutable::now();

        $values = ['status' => $status->value];

        if ($status === ProjectStatus::InProgress) {
            $values['started_at'] = $changedAt;
        }

        if ($status === ProjectStatus::Completed) {
            $values['completed_at'] = $changedAt;
        }

        return $this->updateGuardedByVersion($project, $values, $expectedVersion);
    }

    public function delete(Project $project, User $deletedBy): void
    {
        Gate::authorize('projects.delete');

        DB::transaction(function () use ($project, $deletedBy): void {
            $project->deleted_by = $deletedBy->id;
            $project->save();

            $project->delete();
        });
    }

    public function restore(Project $project): Project
    {
        Gate::authorize('projects.restore');

        $project->deleted_by = null;
        $project->restore();

        return $project;
    }

    /**
     * Do not wrap this in a catch for unique violations: firstOrCreate defers to
     * createOrFirst, which already catches one and re-reads the winner. The unique index
     * on clients.name_key is what makes concurrent creation of one client safe.
     */
    private function resolveClient(string $name): Client
    {
        return Client::firstOrCreate(
            ['name_key' => Client::keyFor($name)],
            ['name' => Str::squish($name)],
        );
    }

    /**
     * Zero affected rows means someone else saved first, or deleted the row.
     *
     * The refresh() is not optional: a query builder update leaves the in-memory model
     * holding the old version, which would fail the caller's next save.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws StaleProjectException
     */
    private function updateGuardedByVersion(Project $project, array $values, int $expectedVersion): Project
    {
        $affected = Project::whereKey($project->getKey())
            ->where('version', $expectedVersion)
            ->update([...$values, 'version' => DB::raw('version + 1')]);

        if ($affected === 0) {
            throw new StaleProjectException($project->fresh());
        }

        return $project->refresh();
    }
}
