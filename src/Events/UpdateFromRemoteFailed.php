<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateFromRemoteFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $remoteHost,
        public readonly string $remoteUser,
        public readonly string $reason
    ) {}
}
