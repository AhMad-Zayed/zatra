<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إتمام الحجز - زاتارا للسياحة</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Tajawal', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts: Tajawal -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col">

    <!-- Header / Navbar -->
    <header class="bg-white border-b border-gray-100 sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <span class="text-2xl font-extrabold text-primary-600 tracking-tight">زاتارا للسياحة</span>
                </div>
                <!-- Navigation -->
                <div class="flex items-center gap-4">
                    @php $tenant = app(\App\Models\Tenant::class); @endphp
                    @if($tenant)
                        <a href="{{ route('storefront.home', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" 
                           class="text-sm font-semibold text-gray-600 hover:text-primary-600 transition-colors">
                            الرئيسية
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 text-gray-400 py-8 border-t border-gray-900 text-center text-xs mt-12">
        <div class="max-w-7xl mx-auto px-4">
            <p class="mb-2">© {{ date('Y') }} زاتارا للسياحة - نظام حجز الرحلات المتكامل.</p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
