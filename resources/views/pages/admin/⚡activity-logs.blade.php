<?php

use App\Models\AdminActivityLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Activity Logs')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $actionFilter = 'all';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function getLogsProperty()
    {
        return AdminActivityLog::with('admin')
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('action', 'like', "%{$this->search}%")
                        ->orWhere('ip_address', 'like', "%{$this->search}%")
                        ->orWhereHas('admin', function ($a) {
                            $a->where('name', 'like', "%{$this->search}%")
                                ->orWhere('email', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->actionFilter !== 'all', fn ($q) => $q->where('action', $this->actionFilter))
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function getDistinctActionsProperty()
    {
        return AdminActivityLog::select('action')->distinct()->orderBy('action')->pluck('action');
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="hover:underline">Dashboard</a>
                <span>›</span>
                <span class="font-semibold text-zinc-900 dark:text-white">Activity Logs</span>
            </div>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">System Audit Trail</h2>
            <p class="text-xs text-zinc-500">Immutable chronological log of all administrative actions, approvals, and mutations.</p>
        </div>
    </div>

    <!-- Logs Container -->
    <div class="rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-4 border-b border-zinc-200 dark:border-zinc-800 gap-3">
            <div class="flex items-center gap-3">
                <div class="relative w-72">
                    <input wire:model.live.debounce.300ms="search"
                           type="search"
                           placeholder="Search action, actor, or IP..."
                           class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                </div>
                <select wire:model.live="actionFilter" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="all">All Actions</option>
                    @foreach ($this->distinctActions as $act)
                        <option value="{{ $act }}">{{ $act }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-zinc-50 text-zinc-500 uppercase font-semibold dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3">Timestamp</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">Staff Actor</th>
                        <th class="px-4 py-3">Target Entity</th>
                        <th class="px-4 py-3">Metadata / Diff</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->logs as $log)
                        <tr wire:key="log-{{ $log->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition align-top">
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-500 text-[11px]">
                                <div>@datetime($log->created_at)</div>
                                <div class="text-[10px] text-zinc-400 font-mono">{{ $log->ip_address }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-0.5 font-mono text-[11px] font-bold text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($log->admin)
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $log->admin->name }}</div>
                                    <div class="text-zinc-400 text-[11px] font-mono">{{ $log->admin->email }}</div>
                                @else
                                    <span class="text-zinc-400 italic">System / Deleted</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 font-mono text-[11px]">
                                @if ($log->subject_type)
                                    <div>{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                                @else
                                    <span class="text-zinc-400 italic">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if (! empty($log->properties))
                                    <details class="cursor-pointer group">
                                        <summary class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 text-[11px] font-medium">
                                            View Details ({{ count($log->properties) }} fields)
                                        </summary>
                                        <pre class="mt-2 p-2 rounded-lg bg-zinc-100 dark:bg-zinc-800 text-[10px] text-zinc-800 dark:text-zinc-200 overflow-x-auto max-w-sm">{{ json_encode($log->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span class="text-zinc-400 italic text-[11px]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-8 text-center text-xs text-zinc-500">
                                No activity logs recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $this->logs->links() }}
        </div>
    </div>
</div>

