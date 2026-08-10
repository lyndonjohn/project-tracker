<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ProjectService;
use App\Validation\ProjectRules;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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
    public ?string $due_from = null;

    #[Url]
    public ?string $due_to = null;

    #[Url]
    public string $sort = 'created_at';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public bool $with_deleted = false;

    #[Url]
    public int $per_page = 25;

    public bool $show_delete = false;

    public ?int $deleting = null;

    public function mount(): void
    {
        $this->discardInvalidListingState();
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, ProjectRules::listing())) {
            $this->discardInvalidListingState($property);
            $this->resetPage();
        }
    }

    /**
     * An unsortable column falls back to the default rather than erroring, since a
     * tampered request is not something the user can act on.
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

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'priority', 'due_from', 'due_to', 'with_deleted');
        $this->resetPage();
    }

    public function confirmDelete(int $project): void
    {
        $this->deleting = $project;
        $this->show_delete = true;
    }

    public function deleteProject(): void
    {
        Gate::authorize('projects.delete');

        try {
            app(ProjectService::class)->delete(
                Project::findOrFail($this->deleting),
                Auth::user(),
            );
        } catch (Throwable $exception) {
            report($exception);

            Flux::toast(variant: 'danger', text: __('Something went wrong. Please try again.'));

            return;
        }

        $this->reset('deleting', 'show_delete');
        unset($this->projects);

        Flux::toast(variant: 'success', text: __('Project deleted.'));
    }

    #[Computed]
    public function deletingProject(): ?Project
    {
        return $this->deleting === null ? null : Project::find($this->deleting);
    }

    #[On('project-updated')]
    public function refreshList(): void
    {
        unset($this->projects);
    }

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
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        return Project::query()
            ->listing([
                'search' => $this->search,
                'status' => $this->status,
                'priority' => $this->priority,
                'due_from' => $this->due_from,
                'due_to' => $this->due_to,
                'sort' => $this->sort,
                'direction' => $this->direction,
                'with_deleted' => $this->with_deleted,
            ])
            ->paginate($this->per_page);
    }

    /**
     * Reset any listing state that fails its rule back to that property's default, so a
     * stale bookmark renders a list rather than an error nobody typed their way into.
     *
     * This is not the sort guard — Project::sorted() is, and it has to stay there even
     * if this goes away. What only this prevents is "?per_page=100000" paginating the
     * whole table, and a non-date reaching the date parsing in Project::listing().
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

    <flux:card class="flex flex-col gap-4">
        {{-- items-start: fields are grids themselves (app.css), so a stretched one
             spreads its label and control out and misaligns against its neighbours. --}}
        <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="xl:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    icon="magnifying-glass"
                    :label="__('Search')"
                    :placeholder="__('Project, client or description')"
                />
            </div>

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

            <flux:input wire:model.live="due_from" type="date" :label="__('Due from')" />

            <flux:input wire:model.live="due_to" type="date" :label="__('Due to')" />
        </div>

        <flux:separator variant="subtle" />

        <div class="flex flex-wrap items-center justify-between gap-4">
            <flux:checkbox wire:model.live="with_deleted" :label="__('Include deleted')" />

            <flux:button variant="subtle" size="sm" icon="arrow-path" wire:click="resetFilters">
                {{ __('Reset search and filters') }}
            </flux:button>
        </div>
    </flux:card>

    <flux:table :paginate="$this->projects">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sort === 'name'" :direction="$direction" wire:click="sortBy('name')">
                {{ __('Name') }}
            </flux:table.column>
            <flux:table.column sortable :sorted="$sort === 'client_name'" :direction="$direction" wire:click="sortBy('client_name')">
                {{ __('Client') }}
            </flux:table.column>
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
                                        data-test="restore-button"
                                    >
                                        {{ __('Restore') }}
                                    </flux:button>
                                @endcan
                            @else
                                @can('projects.update-status')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="flag"
                                        wire:click="$dispatch('change-project-status', { project: {{ $project->id }} })"
                                        data-test="status-button"
                                    >
                                        {{ __('Status') }}
                                    </flux:button>
                                @endcan

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

                                @can('projects.delete')
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="confirmDelete({{ $project->id }})"
                                        data-test="delete-button"
                                    >
                                        {{ __('Delete') }}
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

    <flux:modal wire:model.self="show_delete" class="md:w-96">
        <div class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Delete project?') }}</flux:heading>

                @if ($this->deletingProject)
                    <flux:text class="mt-2">
                        {{ __('":name" will be removed from the list. You can restore it later.', ['name' => $this->deletingProject->name]) }}
                    </flux:text>
                @endif
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteProject">{{ __('Delete project') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <livewire:pages::projects.status-modal />
</div>
