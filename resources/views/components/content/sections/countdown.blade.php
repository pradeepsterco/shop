@props([
    'section',
])

@php
    $settings = $section->settings;
    $items = $section->items;
    $endTimeUtc = $settings['end_time'] ?? now()->addDay()->toIso8601String();
    $headline = $settings['headline'] ?? 'Flash Sale Ending Soon!';
    $subheadline = $settings['subheadline'] ?? '';
    $badge = $settings['badge'] ?? 'Limited Time Offer';
    $displayEnd = format_datetime($endTimeUtc);
@endphp

<div
    x-data="{
        endTime: new Date('{{ $endTimeUtc }}').getTime(),
        days: '00',
        hours: '00',
        minutes: '00',
        seconds: '00',
        expired: false,
        timer: null,
        init() {
            this.update();
            this.timer = setInterval(() => this.update(), 1000);
        },
        update() {
            const now = new Date().getTime();
            const diff = this.endTime - now;

            if (diff <= 0) {
                this.expired = true;
                if (this.timer) clearInterval(this.timer);
                return;
            }

            this.days = String(Math.floor(diff / (1000 * 60 * 60 * 24))).padStart(2, '0');
            this.hours = String(Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))).padStart(2, '0');
            this.minutes = String(Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
            this.seconds = String(Math.floor((diff % (1000 * 60)) / 1000)).padStart(2, '0');
        }
    }"
    x-show="!expired"
    class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"
>
    <div class="rounded-2xl bg-gradient-to-r from-red-600 via-rose-600 to-amber-600 p-6 sm:p-10 text-white shadow-md flex flex-col md:flex-row items-center justify-between gap-6">
        <div class="space-y-2 text-center md:text-left max-w-lg">
            @if ($badge)
                <span class="inline-block rounded-full bg-white/20 backdrop-blur px-3 py-1 text-[11px] font-extrabold uppercase tracking-wider text-white">
                    {{ $badge }}
                </span>
            @endif
            <h2 class="text-2xl sm:text-3xl font-black tracking-tight leading-tight">
                {{ $headline }}
            </h2>
            @if ($subheadline)
                <p class="text-xs sm:text-sm text-red-100">
                    {{ $subheadline }}
                </p>
            @endif
            <p class="text-[11px] text-red-200">
                Ends on: <strong>{{ $displayEnd }}</strong>
            </p>
        </div>

        <!-- Timer Badges -->
        <div class="flex items-center gap-2 sm:gap-3 text-center">
            <div class="flex flex-col items-center justify-center rounded-xl bg-black/40 backdrop-blur size-16 sm:size-20 border border-white/10">
                <span class="text-xl sm:text-2xl font-black font-mono tracking-tight" x-text="days">00</span>
                <span class="text-[9px] sm:text-[10px] uppercase font-bold text-red-200">Days</span>
            </div>
            <span class="text-xl font-bold">:</span>
            <div class="flex flex-col items-center justify-center rounded-xl bg-black/40 backdrop-blur size-16 sm:size-20 border border-white/10">
                <span class="text-xl sm:text-2xl font-black font-mono tracking-tight" x-text="hours">00</span>
                <span class="text-[9px] sm:text-[10px] uppercase font-bold text-red-200">Hours</span>
            </div>
            <span class="text-xl font-bold">:</span>
            <div class="flex flex-col items-center justify-center rounded-xl bg-black/40 backdrop-blur size-16 sm:size-20 border border-white/10">
                <span class="text-xl sm:text-2xl font-black font-mono tracking-tight" x-text="minutes">00</span>
                <span class="text-[9px] sm:text-[10px] uppercase font-bold text-red-200">Mins</span>
            </div>
            <span class="text-xl font-bold">:</span>
            <div class="flex flex-col items-center justify-center rounded-xl bg-black/40 backdrop-blur size-16 sm:size-20 border border-white/10">
                <span class="text-xl sm:text-2xl font-black font-mono tracking-tight text-amber-300" x-text="seconds">00</span>
                <span class="text-[9px] sm:text-[10px] uppercase font-bold text-red-200">Secs</span>
            </div>
        </div>
    </div>
</div>

