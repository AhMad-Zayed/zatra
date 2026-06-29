<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? ($currentTenant->name ?? 'Zatara Tours & Travel') }}</title>

    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @stack('head')

    <style>
        body {
            font-family: 'Tajawal', 'Cairo', sans-serif;
            background-color: #FFFFFF;
        }
        /* Scrollbar */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col text-slate-800">

    {{-- ========================== NAVIGATION ========================== --}}
    <nav x-data="{ scrolled: false }" 
         @scroll.window="scrolled = (window.pageYOffset > 50)"
         :class="scrolled ? 'glass-panel border-b-0' : 'bg-transparent'"
         class="fixed top-0 w-full z-50 transition-all duration-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-16">
            <div class="flex justify-between h-24 items-center gap-6">

                {{-- Logo --}}
                @if(isset($currentTenant))
                    <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}" class="flex items-center gap-3 group flex-shrink-0">
                        <img src="{{ asset('images/logo.png') }}" alt="{{ $currentTenant->name }}" class="h-12 object-contain" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="hidden items-center gap-3">
                            <span class="text-2xl font-bold tracking-wide transition-colors" :class="scrolled ? 'text-zatara-blue' : 'text-white'">
                                {{ $currentTenant->name }}
                            </span>
                        </div>
                    </a>
                @endif

                {{-- Nav Links (Desktop) --}}
                <div class="hidden md:flex items-center gap-8 text-base flex-1 font-medium transition-colors" :class="scrolled ? 'text-slate-700' : 'text-white/90'">
                    @if(isset($currentTenant))
                        <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}" class="hover:text-zatara-gold transition-colors">الرحلات</a>
                    @endif
                    <a href="#" class="hover:text-zatara-gold transition-colors">وجهاتنا</a>
                    <a href="#" class="hover:text-zatara-gold transition-colors">عن زتارة</a>
                    <a href="#" class="hover:text-zatara-gold transition-colors">اتصل بنا</a>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-4 flex-shrink-0">
                    {{-- Search Icon (Micro-interaction) --}}
                    <button class="transition-colors hidden md:block" :class="scrolled ? 'text-zatara-blue hover:text-zatara-gold' : 'text-white hover:text-zatara-gold'">
                        <span class="material-symbols-outlined text-[28px]">search</span>
                    </button>

                    {{-- WhatsApp Button --}}
                    @if(isset($currentTenant))
                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->phone ?? '970599000000') }}"
                           target="_blank"
                           class="hidden md:flex items-center gap-2 px-6 py-2.5 rounded-2xl text-sm font-bold transition-all border"
                           :class="scrolled ? 'bg-zatara-blue text-white border-transparent hover:shadow-lg hover:shadow-zatara-blue/30' : 'glass-panel text-white border-white/20 hover:bg-white hover:text-zatara-blue'">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                            واتساب
                        </a>
                    @endif

                    @auth('customer')
                        <a href="#" class="transition-colors" :class="scrolled ? 'text-zatara-blue' : 'text-white'">
                            <span class="material-symbols-outlined text-[32px]" style="font-variation-settings:'FILL' 0">account_circle</span>
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    {{-- ========================== MAIN ========================== --}}
    <main class="flex-grow">
        {{ $slot }}
    </main>

    {{-- ========================== FOOTER ========================== --}}
    <footer class="bg-[#0f172a] text-white mt-32 relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.03] pointer-events-none" style="background-image: radial-gradient(#f59e0b 1px, transparent 1px); background-size: 24px 24px;"></div>
        <div class="max-w-7xl mx-auto px-6 md:px-16 py-16 relative z-10 border-t-4 border-zatara-gold">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-12">
                {{-- Column 1: Agency Info & License --}}
                <div class="col-span-1">
                    @if(isset($currentTenant))
                        <div class="font-bold text-3xl text-zatara-gold mb-4">{{ $currentTenant->name }}</div>
                    @endif
                    <p class="text-sm text-slate-400 font-light leading-relaxed mb-4">
                        نقدم لك تجارب سفر مصممة بعناية لتلبي طموحك في اكتشاف العالم برفاهية مطلقة وخدمة لا تُضاهى.
                    </p>
                    @if(!empty($currentTenant->tourism_license_number))
                        <div class="inline-block bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-300">
                            <span class="block text-slate-500 mb-1">ترخيص وزارة السياحة</span>
                            <strong class="text-white">{{ $currentTenant->tourism_license_number }}</strong>
                        </div>
                    @endif
                </div>

                {{-- Column 2: Legal & Trust --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">روابط سريعة</h4>
                    <ul class="space-y-3 text-slate-400 font-light text-sm">
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'terms']) }}" class="hover:text-zatara-gold transition-colors">الشروط والأحكام</a></li>
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'privacy']) }}" class="hover:text-zatara-gold transition-colors">سياسة الخصوصية</a></li>
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'refund']) }}" class="hover:text-zatara-gold transition-colors">سياسة الاسترجاع والإلغاء</a></li>
                    </ul>
                </div>

                {{-- Column 3: FAQs --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">الأسئلة الشائعة</h4>
                    <ul class="space-y-3 text-slate-400 font-light text-sm">
                        @php
                            $faqs = $currentTenant->settings['faqs'] ?? [];
                        @endphp
                        @forelse(array_slice($faqs, 0, 4) as $faq)
                            <li class="line-clamp-1"><a href="#" class="hover:text-zatara-gold transition-colors" title="{{ $faq['question'] ?? '' }}">{{ $faq['question'] ?? '' }}</a></li>
                        @empty
                            <li>لا توجد أسئلة شائعة حالياً.</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Column 4: Contact & Socials --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">تواصل معنا</h4>
                    <div class="space-y-3 text-slate-400 font-light text-sm mb-6">
                        @if(!empty($currentTenant->settings['office_address']))
                            <div class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">location_on</span>
                                <span>{{ $currentTenant->settings['office_address'] }}</span>
                            </div>
                        @endif
                        @if(!empty($currentTenant->settings['contact_phone']))
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">call</span>
                                <span dir="ltr">{{ $currentTenant->settings['contact_phone'] }}</span>
                            </div>
                        @endif
                        @if(!empty($currentTenant->settings['working_hours']))
                            <div class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">schedule</span>
                                <span>{{ $currentTenant->settings['working_hours'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex gap-3">
                        @if(!empty($currentTenant->settings['facebook_url']))
                            <a href="{{ $currentTenant->settings['facebook_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all">
                                F
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['instagram_url']))
                            <a href="{{ $currentTenant->settings['instagram_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all">
                                I
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['tiktok_url']))
                            <a href="{{ $currentTenant->settings['tiktok_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all">
                                T
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['whatsapp_number']))
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number']) }}" target="_blank" class="w-10 h-10 rounded-full bg-[#25D366]/20 flex items-center justify-center text-[#25D366] hover:bg-[#25D366] hover:text-white transition-all">
                                W
                            </a>
                        @endif
                    </div>
                </div>
            </div>
            
            <div class="border-t border-white/10 pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-sm text-center md:text-right text-slate-500 font-light">
                    © {{ date('Y') }} {{ $currentTenant->name ?? 'Zatara Tours & Travel' }}. جميع الحقوق محفوظة.
                </div>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
