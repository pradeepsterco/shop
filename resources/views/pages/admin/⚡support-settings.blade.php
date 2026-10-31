<?php

namespace App\Livewire\Admin;

use App\Helpdesk\Services\GrievanceOfficerService;
use App\Models\Admin;
use App\Support\AdminPermissions;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Grievance Officer')] class extends Component {
    public string $grievance_name = '';

    public string $grievance_designation = '';

    public string $grievance_email = '';

    public string $grievance_phone = '';

    public string $grievance_address = '';

    public string $grievance_hours = '';

    public function mount(GrievanceOfficerService $officers): void
    {
        $this->authorize(AdminPermissions::SUPPORT_SETTINGS);

        foreach ($officers->get() as $field => $value) {
            $this->{$field} = (string) $value;
        }
    }

    public function save(GrievanceOfficerService $officers): void
    {
        $this->authorize(AdminPermissions::SUPPORT_SETTINGS);

        /** @var Admin $admin */
        $admin = auth('admin')->user();

        try {
            $officers->update($this->only(GrievanceOfficerService::FIELDS), $admin);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('success', 'Grievance officer details saved. They are now shown on the public grievance page.');
    }
}; ?>

<div class="max-w-3xl space-y-6">
    <div>
        <a href="{{ route('admin.support.index') }}" wire:navigate class="text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white">&larr; Support inbox</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Grievance Officer</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Shown on the public <a href="{{ route('grievance-officer') }}" target="_blank" class="font-medium text-sky-700 hover:underline dark:text-sky-400">grievance officer page</a>, as the Consumer Protection (E-Commerce) Rules, 2020 require.
        </p>
    </div>

    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">{{ session('success') }}</div>
    @endif

    <form wire:submit="save" class="grid gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs sm:grid-cols-2 dark:border-zinc-800 dark:bg-zinc-900">
        @foreach ([
            'grievance_name' => ['Name', 'text'],
            'grievance_designation' => ['Designation', 'text'],
            'grievance_email' => ['Email', 'email'],
            'grievance_phone' => ['Phone (optional)', 'text'],
            'grievance_hours' => ['Working hours (optional)', 'text'],
        ] as $field => [$label, $type])
            <div>
                <label for="{{ $field }}" class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $label }}</label>
                <input id="{{ $field }}" type="{{ $type }}" wire:model="{{ $field }}"
                       class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white" />
                @error($field) <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div class="sm:col-span-2">
            <label for="grievance_address" class="mb-1 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">Address</label>
            <textarea id="grievance_address" wire:model="grievance_address" rows="3"
                      class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white"></textarea>
            @error('grievance_address') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end sm:col-span-2">
            <button type="submit" class="rounded-xl bg-zinc-900 px-5 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">Save</button>
        </div>
    </form>
</div>
