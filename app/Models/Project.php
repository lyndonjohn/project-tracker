<?php

namespace App\Models;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Validation\ProjectRules;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $client_id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus|null $status
 * @property ProjectPriority|null $priority
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property int $version
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['client_id', 'name', 'description', 'status', 'priority', 'start_date', 'due_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Mirrors the column default: a freshly created model does not re-read the row, so
     * without this the version would be null in memory right after a create.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * The listing query, shared by the index page and the API list endpoint so neither
     * surface can search, filter or sort differently from the other. The client is
     * eager-loaded here rather than by the callers, because every listing renders its
     * name and a caller that forgot would turn one query into one per row.
     *
     * @param  Builder<Project>  $query
     * @param  array{search?: string|null, status?: string|null, priority?: string|null, due_from?: string|null, due_to?: string|null, sort?: string|null, direction?: string|null, with_deleted?: bool}  $filters
     */
    #[Scope]
    protected function listing(Builder $query, array $filters): void
    {
        $search = $filters['search'] ?? null;
        $dueFrom = $filters['due_from'] ?? null;
        $dueTo = $filters['due_to'] ?? null;

        $query->with('client')
            ->when($filters['with_deleted'] ?? false, fn (Builder $query) => $query->withTrashed())
            ->when(filled($search), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('projects.name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%")),
            ))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            // Bounded by the whole day because MySQL stores a date column as
            // "2026-03-15" and SQLite as "2026-03-15 00:00:00", so the bare date would
            // drop rows due on the "to" date under SQLite. Keeps the due_date index,
            // which whereDate() would lose.
            ->when(filled($dueFrom) && filled($dueTo), fn (Builder $query) => $query
                ->where('due_date', '>=', CarbonImmutable::parse($dueFrom)->startOfDay())
                ->where('due_date', '<=', CarbonImmutable::parse($dueTo)->endOfDay()))
            ->sorted($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc');
    }

    /**
     * The whitelist guard belongs here, not only in the callers: orderBy() interpolates
     * identifiers rather than binding them.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function sorted(Builder $query, string $column, string $direction): void
    {
        $column = in_array($column, ProjectRules::SORTABLE, true) ? $column : 'created_at';
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        // The client's name lives on another table; a correlated subquery orders by it
        // without a join, which would otherwise have to be de-duplicated before paginating.
        $query->orderBy(
            $column === 'client_name'
                ? Client::select('name')->whereColumn('clients.id', 'projects.client_id')
                : $column,
            $direction,
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'start_date' => 'date',
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
