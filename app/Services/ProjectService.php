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
 * The project business logic, shared by the Livewire UI and the API.
 *
 * Every method receives data that has already been validated by ProjectRules, so nothing
 * here re-checks field shapes; what it does own is client resolution, the status
 * timestamps, and the optimistic-locking guard.
 */
class ProjectService
{
    /**
     * Create a project, creating its client too when the name is new.
     *
     * Two tables are written, so the whole thing is one transaction.
     *
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
     * Update everything about a project except its status.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws StaleProjectException when someone else saved first
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
     * Move a project to a new status, stamping the timestamp that status owns.
     *
     * Transitions are unrestricted, so moving away from In Progress or Completed leaves
     * the timestamp that was already recorded alone.
     *
     * @throws StaleProjectException when someone else saved first
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

    /**
     * Soft-delete a project, recording who did it.
     */
    public function delete(Project $project, User $deletedBy): void
    {
        Gate::authorize('projects.delete');

        DB::transaction(function () use ($project, $deletedBy): void {
            $project->deleted_by = $deletedBy->id;
            $project->save();

            $project->delete();
        });
    }

    /**
     * Restore a soft-deleted project and forget who deleted it.
     */
    public function restore(Project $project): Project
    {
        Gate::authorize('projects.restore');

        $project->deleted_by = null;
        $project->restore();

        return $project;
    }

    /**
     * Find the client this name refers to, creating it the first time it is seen.
     *
     * The lookup goes through the canonical key rather than the name, so casing and
     * spacing differences resolve to one client instead of quietly creating a second.
     *
     * firstOrCreate is a select followed by an insert, so two requests naming the same
     * new client both miss the select and both insert, and the loser hits the unique
     * index. A transaction does not help — the conflict is between transactions rather
     * than inside one. Laravel already resolves this: firstOrCreate defers to
     * createOrFirst, which wraps the insert in a savepoint, catches
     * UniqueConstraintViolationException, and re-reads the winning row over the write
     * connection. Catching it again here would only duplicate that, so the unique index
     * on clients.name_key is what makes this safe, and the user sees a successful save.
     */
    private function resolveClient(string $name): Client
    {
        return Client::firstOrCreate(
            ['name_key' => Client::keyFor($name)],
            ['name' => Str::squish($name)],
        );
    }

    /**
     * Write the given values only if the project is still on the version the caller loaded.
     *
     * Zero affected rows means someone else saved first — including someone who deleted
     * the project, since a trashed row is outside this query's scope.
     *
     * The update goes through the query builder, so it fires no model events and leaves
     * the in-memory model holding its old attributes and, more damagingly, its old
     * version. Refreshing before returning is what keeps the caller's next save from
     * failing against a version that has already moved.
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
