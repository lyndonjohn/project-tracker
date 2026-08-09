<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';

    /**
     * The human-readable label for this status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::InProgress => 'In progress',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
        };
    }
}
