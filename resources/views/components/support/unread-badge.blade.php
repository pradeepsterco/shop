@props(['count' => 0])

@if ($count > 0)
    <span {{ $attributes->merge(['class' => 'ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white']) }}
          data-test="support-unread-badge" title="{{ $count }} unread">
        {{ $count > 99 ? '99+' : $count }}
    </span>
@endif
