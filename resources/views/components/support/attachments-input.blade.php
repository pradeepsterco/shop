@props([
    // The Livewire component's array of TemporaryUploadedFile (bound with wire:model="attachments")
    'files' => [],
    'label' => null,
])

<div>
    @if ($label)
        <label class="mb-1.5 block text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $label }} <span class="font-normal text-zinc-400">(up to {{ config('helpdesk.max_attachments') }} images or PDFs, 5 MB each)</span></label>
    @endif
    <input type="file" wire:model="attachments" multiple accept=".jpg,.jpeg,.png,.webp,.pdf"
           class="block w-full text-xs text-zinc-600 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-800 dark:file:text-zinc-200" />
    <div wire:loading wire:target="attachments" class="mt-1 text-xs text-zinc-500">Uploading…</div>
    @if ($files)
        <ul class="mt-2 space-y-1">
            @foreach ($files as $i => $file)
                <li class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-1.5 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" wire:key="att-{{ $i }}">
                    <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                    <button type="button" wire:click="removeAttachment({{ $i }})" class="ml-2 text-rose-600 hover:text-rose-700 dark:text-rose-400">Remove</button>
                </li>
            @endforeach
        </ul>
    @endif
    @error('attachments') <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $message }}</p> @enderror
</div>
