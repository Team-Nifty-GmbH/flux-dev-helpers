<?php

namespace TeamNiftyGmbH\FluxDevHelpers;

use FluxErp\Events\MailAccount\Connecting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TeamNiftyGmbH\FluxOffice365\Listeners\MailAccountConnectingListener;

class FluxDevHelpersServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {

    }

    protected function offerPublishing(): void
    {

    }
}
