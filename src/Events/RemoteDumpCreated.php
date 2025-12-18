<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RemoteDumpCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $remoteHost,
        public readonly string $remoteUser
    ) {}
}
