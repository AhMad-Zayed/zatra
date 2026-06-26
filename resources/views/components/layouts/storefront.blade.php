<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $currentTenant->name }}</title>

    <!-- Tailwind / Vite -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @livewireStyles
</head>
<body class="bg-slate-900 text-slate-100 font-sans antialiased min-h-screen flex flex-col selection:bg-amber-500 selection:text-slate-900">
    
    <!-- Header -->
    <header class="bg-slate-950 border-b border-slate-800 shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-20 items-center">
                <!-- Logo & Agency Name -->
                <div class="flex-shrink-0 flex items-center gap-3">
                    <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}" class="flex items-center gap-3 group">
                        <div class="w-10 h-10 bg-amber-500 rounded-lg flex items-center justify-center text-slate-900 font-bold text-xl transition-transform group-hover:scale-105">
                            {{ mb_substr($currentTenant->name, 0, 1) }}
                        </div>
                        <span class="text-xl font-bold tracking-tight text-white transition-colors group-hover:text-amber-500">
                            {{ $currentTenant->name }}
                        </span>
                    </a>
                </div>
                
                <!-- Optional: User / Support Action -->
                <div class="flex items-center gap-4">
                    <a href="#" class="text-sm font-medium text-slate-300 hover:text-amber-500 transition-colors">
                        اتصل بنا
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow">
        <div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
            {{ $slot }}
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-950 border-t border-slate-800 mt-auto">
        <div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8 text-center">
            <p class="text-slate-500 text-sm">
                &copy; {{ date('Y') }} {{ $currentTenant->name }}. جميع الحقوق محفوظة.
            </p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
