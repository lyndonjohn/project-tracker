<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ProjectService;
use App\Validation\ProjectRules;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projects')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $priority = null;

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public bool $with_deleted = false;

    #[Url]
    public int $per_page = 25;

    /**
     * Every listing property is bound to the query string, so all of them arrive
     * user-controlled — not just the ones a select normally supplies.
     */
    public function mount(): void
    {
        $this->discardInvalidListingState();
    }

    /**
     * Re-check a listing property after it changes, and go back to page one.
     */
    public function updated(string $property): void
    {
        if (array_key_exists($property, ProjectRules::listing())) {
            $this->discardInvalidListingState($property);
            $this->resetPage();
        }
    }

    /**
     * Sort by a column, or fall back to the default if the column is not sortable.
     *
     * The column reaches orderBy(), which interpolates identifiers rather than binding
     * them, so anything outside the whitelist is discarded. A tampered request renders
     * the default list rather than erroring at a user who did not cause it.
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, ProjectRules::SORTABLE, true)) {
            $this->reset('sort', 'direction');

            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Restore a soft-deleted project.
     */
    public function restore(int $project): void
    {
        Gate::authorize('projects.restore');

        try {
            app(ProjectService::class)->restore(Project::withTrashed()->findOrFail($project));
        } catch (Throwable $exception) {
            report($exception);

            Flux::toast(variant: 'danger', text: __('Something went wrong. Please try again.'));

            return;
        }

        unset($this->projects);

        Flux::toast(variant: 'success', text: __('Project restored.'));
    }

    /**
     * The current page of projects.
     *
     * The client is eager-loaded because every row renders its name; without it a
     * twenty-five row page would issue twenty-six queries.
     *
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return Project::query()
            ->with('client')
            ->when($this->with_deleted, fn (Builder $query) => $query->withTrashed())
            ->when($this->search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->where('projects.name', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%")
                    ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$this->search}%")),
            ))
            ->when($this->status, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->priority, fn (Builder $query) => $query->where('priority', $this->priority))
            ->orderBy($this->sort, $this->direction)
            ->paginate($this->per_page);
    }

    /**
     * Reset any listing state that fails its rule back to that property's default.
     *
     * These are navigational state rather than a form being filled in, so a stale
     * bookmark or a tampered URL shows a sensible list instead of a validation message
     * the user cannot act on. validateOnly() throws on failure, which is what the catch
     * turns into the reset.
     */
    private function discardInvalidListingState(?string $property = null): void
    {
        $rules = ProjectRules::listing();

        foreach ($property === null ? array_keys($rules) : [$property] as $name) {
            try {
                $this->validateOnly($name, $rules);
            } catch (ValidationException) {
                $this->reset($name);
                $this->resetErrorBag($name);
            }
        }
    }
}; ?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl">{{ __('Projects') }}</flux:heading>

        @can('projects.create')
            <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>
                {{ __('New project') }}
            </flux:button>
        @endcan
    </div>

    <div class="grid gap-4 md:grid-cols-4">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :label="__('Search')"
            :placeholder="__('Project, client or description')"
        />

        <flux:select wire:model.live="status" :label="__('Status')" :placeholder="__('All statuses')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (ProjectStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="priority" :label="__('Priority')" :placeholder="__('All priorities')">
            <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
            @foreach (ProjectPriority::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-end">
            <flux:checkbox wire:model.live="with_deleted" :label="__('Include deleted')" />
        </div>
    </div>

    <flux:table :paginate="$this->projects">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sort === 'name'" :direction="$direction" wire:click="sortBy('name')">
                {{ __('Name') }}
            </flux:table.column>
            <flux:table.column>{{ __('Client') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sort === 'status'" :direction="$direction" wire:click="sortBy('status')">
                {{ __('Status') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sort === 'priority'" :direction="$direction" wire:click="sortBy('priority')">
                {{ __('Priority') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sort === 'due_date'" :direction="$direction" wire:click="sortBy('due_date')">
                {{ __('Due date') }}
            </flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->projects as $project)
                <flux:table.row :key="$project->id">
                    <flux:table.cell variant="strong">
                        {{ $project->name }}

                        @if ($project->trashed())
                            <flux:badge size="sm" color="zinc">{{ __('Deleted') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $project->client->name }}</flux:table.cell>
                    <flux:table.cell>{{ $project->status?->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $project->priority?->label() }}</flux:table.cell>
                    <flux:table.cell>{{ $project->due_date?->toFormattedDateString() }}</flux:table.cell>
                    <flux:table.cell class="py-0">
                        <div class="flex justify-end gap-2">
                            @if ($project->trashed())
                                @can('projects.restore')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="arrow-uturn-left"
                                        wire:click="restore({{ $project->id }})"
                                    >
                                        {{ __('Restore') }}
                                    </flux:button>
                                @endcan
                            @else
                                @can('projects.edit-details')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="pencil-square"
                                        :href="route('projects.edit', $project)"
                                        wire:navigate
                                    >
                                        {{ __('Edit') }}
                                    </flux:button>
                                @endcan
                            @endif
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6">{{ __('No projects found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
</flux:table>
</div>
