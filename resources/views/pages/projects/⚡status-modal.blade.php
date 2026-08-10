<?php

use App\Enums\ProjectStatus;
use App\Exceptions\StaleProjectException;
use App\Livewire\Forms\ProjectStatusForm;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ProjectStatusForm $form;

    public bool $show = false;

    #[On('change-project-status')]
    public function open(int $project): void
    {
        $this->form->setProject(Project::findOrFail($project));
        $this->resetErrorBag();
        $this->show = true;
    }

    public function save(): void
    {
        try {
            $this->form->save();
        } catch (StaleProjectException) {
            Flux::toast(
                variant: 'warning',
                text: __('This project was updated by someone else. Refresh to see the latest version.'),
            );

            return;
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Flux::toast(variant: 'danger', text: __('Something went wrong. Please try again.'));

            return;
        }

        $this->show = false;

        Flux::toast(variant: 'success', text: __('Status updated.'));

        $this->dispatch('project-updated');
    }

    /**
     * Only these two statuses write a timestamp, so only they need one confirmed.
     */
    public function stampsATimestamp(): bool
    {
        return in_array($this->form->status, [
            ProjectStatus::InProgress->value,
            ProjectStatus::Completed->value,
        ], true);
    }
}; ?>

<flux:modal wire:model.self="show" class="md:w-96">
    <form wire:submit="save" class="flex flex-col gap-6">
        <div>
            <flux:heading size="lg">{{ __('Change status') }}</flux:heading>

            @if ($form->project)
                <flux:text class="mt-2">{{ $form->project->name }}</flux:text>
            @endif
        </div>

        {{-- A project may have no status yet, so null needs an option of its own. Not
             flux:select's placeholder prop: it renders the option disabled, and a browser
             will not hold a disabled option selected, so it falls through to the first
             real status while the bound value stays null — the form then looks filled in
             and fails validation on save. --}}
        <flux:select wire:model.live="form.status" :label="__('Status')">
            <flux:select.option value="">{{ __('Choose a status') }}</flux:select.option>
            @foreach (ProjectStatus::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->stampsATimestamp())
            <flux:input
                wire:model="form.changed_at"
                type="datetime-local"
                :label="$form->status === App\Enums\ProjectStatus::Completed->value ? __('Completed at') : __('Started at')"
            />
        @endif

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" type="submit">{{ __('Save status') }}</flux:button>
        </div>
    </form>
</flux:modal>
