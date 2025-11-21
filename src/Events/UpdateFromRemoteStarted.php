<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateFromRemoteStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $remoteHost,
        public readonly string $remoteUser,
        public readonly bool $useLocal,
        public readonly bool $shouldSyncStorage,
        public readonly bool $shouldDeleteDump
    ) {
    }
}
