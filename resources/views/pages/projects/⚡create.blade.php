<?php

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Livewire\Forms\ProjectForm;
use App\Models\Client;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New project')] class extends Component {
    public ProjectForm $form;

    /**
     * Today is only a prefill, so the column itself stays honest about what was saved.
     */
    public function mount(): void
    {
        $this->form->start_date = CarbonImmutable::now()->toDateString();
    }

    /**
     * Existing client names, offered as suggestions on a field that still takes free text.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function clientNames(): Collection
    {
        return Client::orderBy('name')->pluck('name');
    }

    /**
     * Create the project and go back to the list.
     */
    public function save(): void
    {
        try {
            $this->form->store();
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Flux::toast(variant: 'danger', text: __('Something went wrong. Please try again.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Project created.'));

        $this->redirectRoute('projects.index', navigate: true);
    }
}; ?>

<div class="flex w-full max-w-2xl flex-col gap-6">
    <flux:heading size="xl">{{ __('New project') }}</flux:heading>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input
            wire:model="form.client_name"
            :label="__('Client')"
            :description="__('Type a new client name to add one.')"
            list="client-names"
            required
            autofocus
        />

        <datalist id="client-names">
            @foreach ($this->clientNames as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>

        <flux:input wire:model="form.name" :label="__('Project name')" required />

        <flux:textarea wire:model="form.description" :label="__('Description')" rows="4" />

        <div class="grid gap-6 md:grid-cols-2">
            <flux:select wire:model="form.status" :label="__('Status')" :placeholder="__('Not set')">
                <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                @foreach (ProjectStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="form.priority" :label="__('Priority')" :placeholder="__('Not set')">
                <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                @foreach (ProjectPriority::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="form.start_date" type="date" :label="__('Start date')" required />

            <flux:input wire:model="form.due_date" type="date" :label="__('Due date')" />
        </div>

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit">{{ __('Create project') }}</flux:button>

            <flux:button variant="ghost" :href="route('projects.index')" wire:navigate>
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
