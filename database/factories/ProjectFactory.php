<?php

namespace Database\Factories;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-3 months', '+1 month');

        return [
            'client_id' => Client::factory(),
            'name' => Str::headline(fake()->unique()->word().' '.fake()->word()),
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectStatus::Planning,
            'priority' => fake()->randomElement(ProjectPriority::cases()),
            'start_date' => $startDate,
            'due_date' => fake()->dateTimeBetween($startDate, '+6 months'),
        ];
    }

    /**
     * Indicate that the project is still being planned.
     */
    public function planning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Planning,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the project is under way, stamping "started_at" as a status change would.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::InProgress,
            'started_at' => now()->subDays(7),
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the project is paused after having been started.
     */
    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::OnHold,
            'started_at' => now()->subDays(14),
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the project is finished, stamping both timestamps in order.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Completed,
            'started_at' => now()->subDays(30),
            'completed_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the project has been soft-deleted by the given user.
     *
     * Named apart from the framework's magic "trashed" state, which only sets "deleted_at".
     */
    public function deletedBy(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
            'deleted_by' => $user->id ?? User::factory(),
        ]);
    }
}
