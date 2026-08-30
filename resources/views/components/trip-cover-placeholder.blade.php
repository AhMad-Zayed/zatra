@props(['seed' => 0])
@php
    $variant = ((int) $seed) % 4;
    $variants = [
        0 => ['bg' => 'bg-gradient-to-br from-zatara-blue via-zatara-blue/90 to-slate-900', 'icon' => 'flight'],
        1 => ['bg' => 'bg-gradient-to-tr from-zatara-blue to-zatara-gold/40', 'icon' => 'travel_explore'],
        2 => ['bg' => 'bg-gradient-to-br from-slate-800 via-zatara-blue/80 to-slate-900', 'icon' => 'landscape'],
        3 => ['bg' => 'bg-gradient-to-tr from-zatara-gold/30 via-zatara-blue/70 to-zatara-blue', 'icon' => 'beach_access'],
    ];
    $v = $variants[$variant];
@endphp
{{--
    Shared "no real photo yet" treatment for trip cover art -- deliberately an honest, clearly-a-
    placeholder design (deterministic gradient + icon + a small "الصورة قريباً" label) rather than
    stock/invented photography. The seed (trip template id) picks one of 4 variants so different
    trips without photos look visually distinct instead of the same identical box repeated across
    the catalog. data-variant is asserted directly in tests/Feature/StorefrontPlaceholderArtTest.php.
--}}
<div {{ $attributes->merge(['class' => "w-full h-full relative overflow-hidden flex items-center justify-center trip-cover-placeholder {$v['bg']}"]) }} data-variant="{{ $variant }}">
    <svg class="absolute inset-0 w-full h-full opacity-10 mix-blend-overlay" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <pattern id="placeholder-pattern-{{ $variant }}-{{ $seed }}" width="100" height="100" patternUnits="userSpaceOnUse">
                <circle cx="50" cy="50" r="10" fill="currentColor"></circle>
                <path d="M0,0 L100,100 M100,0 L0,100" stroke="currentColor" stroke-width="2"></path>
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#placeholder-pattern-{{ $variant }}-{{ $seed }})" class="text-white"></rect>
    </svg>

    <div class="relative z-10 flex items-center justify-center w-20 h-20 rounded-full bg-white/10 backdrop-blur-sm border border-white/20">
        <span class="material-symbols-outlined text-white/80 text-4xl">{{ $v['icon'] }}</span>
    </div>

    <span class="absolute bottom-3 right-3 z-10 text-[11px] font-medium text-white/70 bg-black/10 backdrop-blur-sm px-2.5 py-1 rounded-full">
        الصورة قريباً
    </span>
</div>
