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
