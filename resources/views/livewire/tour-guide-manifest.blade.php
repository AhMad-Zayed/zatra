<div class="min-h-screen bg-slate-50 py-8 px-4 sm:px-6 lg:px-8 font-cairo" dir="rtl">
    <div class="max-w-4xl mx-auto space-y-6">
        
        <!-- Header -->
        <div class="bg-white rounded-3xl shadow-sm p-6 border border-slate-200">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800">{{ $trip->tripTemplate->title }}</h1>
                    <p class="text-slate-500 mt-1 flex items-center">
                        <span class="material-symbols-outlined text-sm ml-1">calendar_today</span>
                        {{ $trip->start_date?->format('Y-m-d') }}
                    </p>
                </div>
                
                <div class="flex gap-3 text-center">
                    <div class="bg-blue-50 p-3 rounded-2xl border border-blue-100 min-w-[80px]">
                        <p class="text-xs text-blue-600 font-bold mb-1">الكل</p>
                        <p class="text-xl font-black text-blue-900">{{ $totalPassengers }}</p>
                    </div>
                    <div class="bg-green-50 p-3 rounded-2xl border border-green-100 min-w-[80px]">
                        <p class="text-xs text-green-600 font-bold mb-1">حاضر</p>
                        <p class="text-xl font-black text-green-900">{{ $checkedInCount }}</p>
                    </div>
                    <div class="bg-red-50 p-3 rounded-2xl border border-red-100 min-w-[80px]">
                        <p class="text-xs text-red-600 font-bold mb-1">غائب</p>
                        <p class="text-xl font-black text-red-900">{{ $totalPassengers - $checkedInCount }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Flash Messages -->
        @if (session()->has('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-2xl flex items-center">
                <span class="material-symbols-outlined ml-2">check_circle</span>
                {{ session('success') }}
            </div>
        @endif
        @if (session()->has('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-2xl flex items-center">
                <span class="material-symbols-outlined ml-2">error</span>
                {{ session('error') }}
            </div>
        @endif

        <!-- Controls -->
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="relative flex-1">
                <span class="material-symbols-outlined absolute top-1/2 right-4 -translate-y-1/2 text-slate-400">search</span>
                <input type="text" wire:model.live="searchTerm" placeholder="ابحث عن راكب أو رقم مقعد..." class="w-full pl-4 pr-12 py-3 rounded-2xl border-slate-200 focus:ring-blue-500 focus:border-blue-500 shadow-sm">
            </div>
            
            <button onclick="toggleScanner()" class="px-6 py-3 bg-slate-800 text-white font-bold rounded-2xl shadow-md hover:bg-slate-900 flex items-center justify-center transition-all">
                <span class="material-symbols-outlined ml-2">qr_code_scanner</span>
                مسح التذكرة
            </button>
        </div>

        <!-- Scanner Container (Hidden by default) -->
        <div id="scanner-container" class="hidden bg-white rounded-3xl shadow-sm border border-slate-200 p-4 animate-fade-in-up">
            <div class="text-center mb-4">
                <h3 class="font-bold text-slate-800">وجّه الكاميرا نحو رمز الاستجابة السريعة (QR Code)</h3>
                <p class="text-sm text-slate-500">سيتم تسجيل حضور الراكب تلقائياً</p>
            </div>
            <div id="reader" width="600px" class="rounded-2xl overflow-hidden border-2 border-dashed border-blue-300"></div>
            <div class="mt-4 text-center">
                <button onclick="toggleScanner()" class="text-slate-500 hover:text-slate-700 text-sm font-medium">إغلاق الكاميرا</button>
            </div>
        </div>

        <!-- Passenger List -->
        <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
            <ul class="divide-y divide-slate-100">
                @forelse($passengers as $p)
                    <li class="p-4 hover:bg-slate-50 transition-colors flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <!-- Seat Number -->
                            <div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg shrink-0
                                {{ $p->is_checked_in ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-700' }}">
                                {{ $p->seat_number ?? '-' }}
                            </div>
                            
                            <!-- Passenger Details -->
                            <div>
                                <p class="font-bold text-slate-800 {{ $p->is_checked_in ? 'line-through opacity-70' : '' }}">
                                    {{ $p->first_name }} {{ $p->last_name }}
                                </p>
                                <div class="flex items-center gap-3 text-xs text-slate-500 mt-1">
                                    <span class="bg-slate-100 px-2 py-1 rounded-md">{{ $p->tripPassengerCategory->name ?? 'مسافر' }}</span>
                                    <span>PNR: {{ $p->booking->pnr ?? $p->booking->id }}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <button wire:click="togglePassenger({{ $p->id }})" class="p-3 rounded-full transition-colors focus:outline-none
                            {{ $p->is_checked_in ? 'text-green-600 bg-green-50 hover:bg-green-100' : 'text-slate-400 bg-slate-50 hover:bg-slate-100' }}">
                            <span class="material-symbols-outlined text-2xl">
                                {{ $p->is_checked_in ? 'check_circle' : 'radio_button_unchecked' }}
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="p-8 text-center text-slate-500">
                        لا يوجد ركاب مطابقين للبحث.
                    </li>
                @endforelse
            </ul>
        </div>
        
    </div>

    <!-- HTML5 QR Code Library -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script>
        let html5QrcodeScanner = null;
        
        function toggleScanner() {
            const container = document.getElementById('scanner-container');
            
            if (container.classList.contains('hidden')) {
                container.classList.remove('hidden');
                startScanner();
            } else {
                container.classList.add('hidden');
                stopScanner();
            }
        }
        
        function startScanner() {
            if (!html5QrcodeScanner) {
                html5QrcodeScanner = new Html5QrcodeScanner(
                    "reader", { fps: 10, qrbox: 250 }, /* verbose= */ false
                );
                
                html5QrcodeScanner.render(onScanSuccess, onScanFailure);
            }
        }
        
        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear().catch(error => {
                    console.error("Failed to clear html5QrcodeScanner. ", error);
                });
                html5QrcodeScanner = null;
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            // Stop scanning temporarily
            stopScanner();
            document.getElementById('scanner-container').classList.add('hidden');
            
            // Call Livewire Component Method
            @this.call('checkInBooking', decodedText);
        }

        function onScanFailure(error) {
            // handle scan failure, usually better to ignore and keep scanning.
            // console.warn(`Code scan error = ${error}`);
        }
    </script>
</div>
