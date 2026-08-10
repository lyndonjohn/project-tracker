<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * Enums are given as both value and label so a client can filter on the value it would
     * send back without having to hold its own copy of the display text.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'client' => [
                'id' => $this->client_id,
                'name' => $this->whenLoaded('client', fn () => $this->client->name),
            ],
            'status' => $this->when($this->status !== null, fn () => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ]),
            'priority' => $this->when($this->priority !== null, fn () => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ]),
            'start_date' => $this->start_date->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Send this back on the next write: it is what makes a stale save a 409
            // instead of a silent overwrite of somebody else's edit.
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at?->toIso8601String()),
            'deleted_by' => $this->when($this->trashed(), fn () => $this->deleted_by),
        ];
    }
}
