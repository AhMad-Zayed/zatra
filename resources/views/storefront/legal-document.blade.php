<x-layouts.storefront>
    <div class="bg-slate-50 min-h-screen py-16">
        <div class="max-w-4xl mx-auto px-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-8 md:p-12">
                <h1 class="text-3xl font-bold text-zatara-blue mb-8 border-b border-slate-100 pb-6">{{ $title }}</h1>
                
                <div class="prose prose-slate prose-lg max-w-none prose-headings:text-zatara-blue prose-a:text-zatara-gold prose-a:no-underline hover:prose-a:underline text-right" dir="rtl">
                    {!! $content !!}
                </div>
            </div>
        </div>
    </div>
</x-layouts.storefront>
