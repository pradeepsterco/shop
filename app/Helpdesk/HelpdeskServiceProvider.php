<?php

namespace App\Helpdesk;

use App\Helpdesk\Console\CloseResolvedTicketsCommand;
use App\Helpdesk\Console\RemindSellersCommand;
use App\Helpdesk\Console\ResolveUnansweredTicketsCommand;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Policies\SupportTicketPolicy;
use App\Helpdesk\Services\GrievanceOfficerService;
use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class HelpdeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/helpdesk.php', 'helpdesk');

        $this->app->singleton(HelpdeskService::class);
        $this->app->singleton(GrievanceOfficerService::class);
    }

    public function boot(): void
    {
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CloseResolvedTicketsCommand::class, RemindSellersCommand::class, ResolveUnansweredTicketsCommand::class]);
        }
    }
}
