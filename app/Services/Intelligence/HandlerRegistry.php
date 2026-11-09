<?php

namespace App\Services\Intelligence;

use App\Services\Intelligence\Handlers\Handler;

/** Finds the handler for an intent. The handlers are registered by IntelligenceServiceProvider. */
final class HandlerRegistry
{
    /** @param  iterable<Handler>  $handlers */
    public function __construct(private readonly iterable $handlers) {}

    public function for(Intent $intent): ?Handler
    {
        foreach ($this->handlers as $handler) {
            if (in_array($intent, $handler->intents(), true)) {
                return $handler;
            }
        }

        return null;
    }
}
