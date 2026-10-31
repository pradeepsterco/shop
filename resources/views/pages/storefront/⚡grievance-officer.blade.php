<?php

namespace App\Livewire\Storefront;

use App\Helpdesk\Services\GrievanceOfficerService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.storefront')] #[Title('Grievance Officer')] class extends Component {
    /**
     * @return array<string, string|null>
     */
    #[Computed]
    public function officer(): array
    {
        return app(GrievanceOfficerService::class)->get();
    }

    #[Computed]
    public function configured(): bool
    {
        return app(GrievanceOfficerService::class)->isConfigured();
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">Grievance Officer</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            In line with the Consumer Protection (E-Commerce) Rules, 2020, {{ brand_name() }} has appointed a Grievance Officer to handle consumer complaints.
        </p>
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        @if ($this->configured)
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Name</dt>
                    <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $this->officer['grievance_name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Designation</dt>
                    <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $this->officer['grievance_designation'] }}</dd>
                </div>
                @if ($this->officer['grievance_email'])
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Email</dt>
                        <dd class="mt-1"><a href="mailto:{{ $this->officer['grievance_email'] }}" class="font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400">{{ $this->officer['grievance_email'] }}</a></dd>
                    </div>
                @endif
                @if ($this->officer['grievance_phone'])
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Phone</dt>
                        <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $this->officer['grievance_phone'] }}</dd>
                    </div>
                @endif
                @if ($this->officer['grievance_hours'])
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Hours</dt>
                        <dd class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $this->officer['grievance_hours'] }}</dd>
                    </div>
                @endif
                @if ($this->officer['grievance_address'])
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Address</dt>
                        <dd class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ $this->officer['grievance_address'] }}</dd>
                    </div>
                @endif
            </dl>
        @else
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Our Grievance Officer's contact details will be published here shortly. Meanwhile, please raise your complaint through Help &amp; Support.</p>
        @endif
    </div>

    <div class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-6 text-sm text-zinc-700 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
        <h2 class="text-base font-bold text-zinc-900 dark:text-white">How to raise a complaint</h2>
        <ol class="list-decimal space-y-1.5 pl-5">
            <li>Sign in and open <a href="{{ route('support.create') }}" class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400">Help &amp; Support</a>, choose the topic and the order, and describe the problem. You get a ticket number straight away.</li>
            <li>If you are not satisfied with the resolution, write to the Grievance Officer with your ticket number.</li>
        </ol>
        <p>We acknowledge every complaint within <strong>48 hours</strong> and resolve it within <strong>one month</strong> of receiving it.</p>
    </div>
</div>
