@props([
    'ticket',
    'messages',
    // 'requester' (customer pages) or 'staff' (admin pages)
    'viewer' => 'requester',
    // Staff only: show "Forward" on customer/seller messages when the ticket has an open linked ticket
    'forwardable' => false,
])

@php
    use App\Helpdesk\Enums\MessageAuthor;

    $attachmentRoute = $viewer === 'staff' ? 'admin.support.attachment' : 'support.attachment';
@endphp

<ol class="space-y-4">
    @foreach ($messages as $message)
        @php
            $mine = $viewer === 'staff' ? $message->author_type === MessageAuthor::Staff : $message->author_type === MessageAuthor::Requester;
        @endphp

        <li wire:key="message-{{ $message->id }}" @class(['flex', 'justify-end' => $mine && $message->author_type !== MessageAuthor::System, 'justify-center' => $message->author_type === MessageAuthor::System])>
            @if ($message->author_type === MessageAuthor::System)
                <div class="flex items-center gap-2 rounded-full bg-zinc-100 px-3 py-1 text-[11px] text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                    @if ($message->is_internal)
                        <span class="font-semibold uppercase tracking-wider text-amber-600 dark:text-amber-400">Internal</span>
                    @endif
                    <span>{{ $message->body }}</span>
                    <span class="text-zinc-400">&middot; @datetime($message->created_at)</span>
                </div>
            @else
                <div @class([
                    'w-full max-w-[92%] rounded-2xl border p-4 sm:max-w-[80%]',
                    'border-amber-300 border-dashed bg-amber-50/70 dark:border-amber-700 dark:bg-amber-950/30' => $message->is_internal,
                    'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60' => ! $message->is_internal && $mine,
                    'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $message->is_internal && ! $mine,
                ])>
                    <div class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                        <span class="font-semibold text-zinc-900 dark:text-white">
                            @if ($message->author_type === MessageAuthor::Staff)
                                {{ $viewer === 'staff' ? ($message->admin?->name ?? 'Former staff') : brand_name().' Support' }}
                            @else
                                {{ $viewer === 'staff' ? $ticket->requester_name : 'You' }}
                            @endif
                        </span>
                        @if ($message->is_internal)
                            <span class="rounded-full bg-amber-200/70 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-800 dark:bg-amber-900/60 dark:text-amber-300">Internal note</span>
                        @endif
                        <span class="text-zinc-400">@datetime($message->created_at)</span>
                        @if ($forwardable && $viewer === 'staff' && $message->author_type === MessageAuthor::Requester && ! $message->is_internal)
                            <button type="button" wire:click="startForward({{ $message->id }})" class="ml-auto rounded-md px-2 py-0.5 text-[11px] font-semibold text-violet-700 hover:bg-violet-100 dark:text-violet-300 dark:hover:bg-violet-900/40">Forward</button>
                        @endif
                    </div>

                    <div class="whitespace-pre-line break-words text-sm text-zinc-700 dark:text-zinc-300">{{ $message->body }}</div>

                    @if ($message->attachments)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($message->attachments as $index => $file)
                                @php
                                    $url = route($attachmentRoute, ['ticket' => $ticket->ticket_number, 'message' => $message->id, 'index' => $index]);
                                @endphp
                                @if (str_starts_with($file['mime'], 'image/'))
                                    <a href="{{ $url }}" target="_blank" rel="noopener" class="block size-20 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" title="{{ $file['name'] }}">
                                        <img src="{{ $url }}" alt="{{ $file['name'] }}" class="size-full object-cover" loading="lazy" />
                                    </a>
                                @else
                                    <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex max-w-[200px] items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                                        <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                        <span class="truncate">{{ $file['name'] }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ol>
