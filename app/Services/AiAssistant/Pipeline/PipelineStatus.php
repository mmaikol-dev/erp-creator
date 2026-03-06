<?php

namespace App\Services\AiAssistant\Pipeline;

enum PipelineStatus: string
{
    case Ready = 'ready';
    case NeedsApproval = 'needs_approval';
    case Paused = 'paused';
    case Completed = 'completed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Ready => in_array($next, [self::NeedsApproval, self::Completed], true),
            self::NeedsApproval => in_array($next, [self::Ready, self::Paused, self::Completed], true),
            self::Paused => in_array($next, [self::Ready, self::Completed], true),
            self::Completed => false,
        };
    }
}
