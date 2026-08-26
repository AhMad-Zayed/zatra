<div class="min-h-screen bg-slate-50 py-12 px-4 sm:px-6 lg:px-8 relative overflow-hidden" dir="rtl">
    <!-- Background Accents -->
    <div class="absolute top-0 right-0 -mr-20 -mt-20 w-96 h-96 rounded-full bg-blue-500 opacity-10 blur-3xl mix-blend-multiply pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 -ml-20 -mb-20 w-96 h-96 rounded-full bg-purple-500 opacity-10 blur-3xl mix-blend-multiply pointer-events-none"></div>

    <div class="max-w-4xl mx-auto relative z-10">
        <!-- Header -->
        <div class="text-center mb-10 space-y-4">
            <h1 class="text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                أهلاً بك في <span class="text-blue-600">{{ $booking->tenant->name }}</span>
            </h1>
            <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                رحلتك القادمة إلى <strong>{{ $booking->tripInstance->tripTemplate->title }}</strong> أصبحت قريبة! يرجى استكمال بيانات الركاب واختيار مقاعدكم لضمان رحلة مريحة وممتعة.
            </p>
        </div>

        <!-- Progress Bar -->
        <div class="mb-10 relative">
            <div class="overflow-hidden h-2 mb-4 text-xs flex rounded-full bg-slate-200 shadow-inner">
                <div style="width: {{ ($step / 4) * 100 }}%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-gradient-to-l from-blue-600 to-purple-600 transition-all duration-500 ease-out"></div>
            </div>
            <div class="flex justify-between text-sm font-medium text-slate-500 px-2">
                <span class="{{ $step >= 1 ? 'text-blue-600 font-bold' : '' }}">الترحيب</span>
                <span class="{{ $step >= 2 ? 'text-blue-600 font-bold' : '' }}">بيانات الركاب</span>
                <span class="{{ $step >= 3 ? 'text-blue-600 font-bold' : '' }}">المقاعد</span>
                <span class="{{ $step >= 4 ? 'text-blue-600 font-bold' : '' }}">الاكتمال</span>
            </div>
        </div>

        <!-- Main Card -->
        <div class="bg-white/80 backdrop-blur-xl border border-slate-200/60 shadow-2xl rounded-3xl p-6 sm:p-10 transition-all duration-300">
            
            @if($isCancelled)
                <div class="text-center py-10 animate-fade-in-up">
                    <div class="w-24 h-24 mx-auto bg-red-50 text-red-600 rounded-full flex items-center justify-center mb-6 shadow-sm border border-red-100">
                        <span class="material-symbols-outlined text-5xl">cancel</span>
                    </div>
                    <h2 class="text-3xl font-bold text-slate-800">عذراً، هذا الحجز ملغى!</h2>
                    <p class="text-lg text-slate-600 mt-4 max-w-lg mx-auto">
                        لا يمكن تعديل بيانات الركاب أو المقاعد لأن هذا الحجز تم إلغاؤه. يرجى التواصل مع خدمة العملاء للمزيد من التفاصيل.
                    </p>
                </div>
            @elseif($isExpired)
                <div class="text-center py-10 animate-fade-in-up">
                    <div class="w-24 h-24 mx-auto bg-yellow-50 text-yellow-600 rounded-full flex items-center justify-center mb-6 shadow-sm border border-yellow-100">
                        <span class="material-symbols-outlined text-5xl">event_busy</span>
                    </div>
                    <h2 class="text-3xl font-bold text-slate-800">الرحلة في الماضي</h2>
                    <p class="text-lg text-slate-600 mt-4 max-w-lg mx-auto">
                        انتهى موعد هذه الرحلة، ولا يمكن تعديل المقاعد أو البيانات بعد الآن. نأمل أن تكونوا قد استمتعتم برحلتكم!
                    </p>
                </div>
            @elseif($step === 1)
                <!-- Step 1: Intro -->
                <div class="space-y-6 text-center animate-fade-in-up">
                    <div class="w-24 h-24 mx-auto bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mb-6 shadow-sm border border-blue-100">
                        <span class="material-symbols-outlined text-5xl">flight_takeoff</span>
                    </div>
                    <h2 class="text-3xl font-bold text-slate-800">تفاصيل حجزك</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-right bg-slate-50 p-6 rounded-2xl border border-slate-100 shadow-inner">
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">الوجهة</p>
                            <p class="font-bold text-slate-800">{{ $booking->tripInstance->tripTemplate->title }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">تاريخ الانطلاق</p>
                            <p class="font-bold text-slate-800">{{ $booking->tripInstance->start_date->format('Y/m/d') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">رقم الحجز</p>
                            <p class="font-mono font-bold text-slate-800">{{ $booking->pnr }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500 uppercase tracking-wider mb-1">عدد الركاب</p>
                            <p class="font-bold text-slate-800">{{ $booking->passengers->count() }} ركاب</p>
                        </div>
                    </div>
                    
                    <button wire:click="nextStep" class="mt-8 inline-flex items-center px-8 py-4 border border-transparent text-lg font-medium rounded-full shadow-lg text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 transform hover:-translate-y-1 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        ابدأ بتعبئة البيانات
                        <span class="material-symbols-outlined mr-2">arrow_forward</span>
                    </button>
                </div>
            @endif

            @if($step === 2)
                <!-- Step 2: Passenger Data -->
                <div class="space-y-8 animate-fade-in-up">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800 flex items-center">
                            <span class="material-symbols-outlined ml-2 text-blue-600">group</span>
                            بيانات الركاب
                        </h2>
                        <p class="text-sm text-slate-500 mt-1">يرجى إدخال البيانات بدقة كما هي في الوثيقة الرسمية.</p>
                    </div>

                    <div class="space-y-6">
                        @foreach($booking->passengers as $p)
                            <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm hover:shadow-md transition-shadow relative overflow-hidden">
                                <!-- Passenger Label Banner -->
                                <div class="absolute top-0 right-0 bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1 rounded-bl-lg border-b border-l border-blue-200">
                                    {{ $p->passenger_label }} - {{ $p->tripPassengerCategory->name ?? '' }}
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">الاسم الأول</label>
                                        <input type="text" wire:model="passengersData.{{ $p->id }}.first_name" class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 transition-colors">
                                        @error("passengersData.{$p->id}.first_name") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">اسم العائلة / الجد</label>
                                        <input type="text" wire:model="passengersData.{{ $p->id }}.last_name" class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 transition-colors">
                                        @error("passengersData.{$p->id}.last_name") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>

                                    @if(in_array('date_of_birth', $requirements))
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">تاريخ الميلاد</label>
                                        <input type="date" wire:model="passengersData.{{ $p->id }}.date_of_birth" class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 transition-colors">
                                        @error("passengersData.{$p->id}.date_of_birth") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    @endif

                                    @if(in_array('document_number', $requirements))
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">رقم الوثيقة (الهوية / الجواز)</label>
                                        <input type="text" wire:model="passengersData.{{ $p->id }}.document_number" class="w-full rounded-xl border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 transition-colors" placeholder="A12345678">
                                        @error("passengersData.{$p->id}.document_number") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    @endif

                                    @if(in_array('passport_image', $requirements))
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700 mb-2">صورة الوثيقة (جواز السفر)</label>
                                        @if($p->hasMedia('identity_documents') && !$passengersData[$p->id]['passport_file'])
                                            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl flex items-center justify-between">
                                                <div class="flex items-center">
                                                    <span class="material-symbols-outlined ml-2">check_circle</span>
                                                    تم رفع الصورة مسبقاً بنجاح
                                                </div>
                                                <label class="text-sm cursor-pointer underline hover:text-green-800">
                                                    تغيير الصورة
                                                    <input type="file" wire:model="passengersData.{{ $p->id }}.passport_file" class="hidden" accept="image/*">
                                                </label>
                                            </div>
                                        @else
                                            <div class="flex items-center justify-center w-full">
                                                <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-300 border-dashed rounded-xl cursor-pointer bg-slate-50 hover:bg-slate-100 transition-colors group">
                                                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                                        <span class="material-symbols-outlined text-slate-400 group-hover:text-blue-500 mb-2 transition-colors">cloud_upload</span>
                                                        <p class="mb-2 text-sm text-slate-500"><span class="font-semibold text-blue-600">اضغط للرفع</span> أو اسحب الصورة هنا</p>
                                                        <p class="text-xs text-slate-500">PNG, JPG (MAX. 5MB)</p>
                                                    </div>
                                                    <input type="file" wire:model="passengersData.{{ $p->id }}.passport_file" class="hidden" accept="image/*" />
                                                </label>
                                            </div>
                                            @if(isset($passengersData[$p->id]['passport_file']) && $passengersData[$p->id]['passport_file'])
                                                <p class="text-green-600 text-sm mt-2 flex items-center"><span class="material-symbols-outlined text-sm ml-1">check</span> تم إرفاق ملف: {{ $passengersData[$p->id]['passport_file']->getClientOriginalName() }}</p>
                                            @endif
                                            @error("passengersData.{$p->id}.passport_file") <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-between mt-8">
                        <button wire:click="previousStep" class="px-6 py-3 border border-slate-300 text-slate-700 font-medium rounded-full hover:bg-slate-50 transition-colors focus:outline-none">
                            عودة
                        </button>
                        <button wire:click="nextStep" class="px-8 py-3 bg-blue-600 text-white font-medium rounded-full shadow-md hover:bg-blue-700 hover:shadow-lg transform hover:-translate-y-0.5 transition-all focus:outline-none">
                            التالي: اختيار المقاعد
                        </button>
                    </div>
                </div>
            @endif

            @if($step === 3)
                <!-- Step 3: Bus Seating Chart -->
                <div class="space-y-8 animate-fade-in-up">
                    <div class="text-center mb-6">
                        <h2 class="text-2xl font-bold text-slate-800 flex items-center justify-center">
                            <span class="material-symbols-outlined ml-2 text-blue-600">directions_bus</span>
                            اختيار المقاعد في الحافلة
                        </h2>
                        <p class="text-sm text-slate-500 mt-2">يرجى تحديد الراكب أولاً، ثم الضغط على المقعد المفضل.</p>
                    </div>

                    @error('seats')
                        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-2xl flex items-center animate-shake">
                            <span class="material-symbols-outlined ml-2">error</span>
                            {{ $message }}
                        </div>
                    @enderror

                    <!-- Passenger Selector -->
                    <div class="flex flex-wrap justify-center gap-3 mb-8">
                        @foreach($booking->passengers as $p)
                            @if($p->tripPassengerCategory && !$p->tripPassengerCategory->requires_seat)
                                @continue
                            @endif
                            @php
                                $hasSeat = isset($selectedSeats[$p->id]) && $selectedSeats[$p->id] !== null;
                            @endphp
                            <div class="px-4 py-2 rounded-xl text-sm font-medium transition-all {{ $hasSeat ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-yellow-100 text-yellow-800 border border-yellow-200 animate-pulse' }}">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">{{ $hasSeat ? 'check_circle' : 'person' }}</span>
                                    {{ $passengersData[$p->id]['first_name'] ?? $p->passenger_label }}
                                    @if($hasSeat)
                                        <span class="bg-white text-green-800 px-2 py-0.5 rounded-full text-xs ml-1 shadow-sm font-bold">مقعد {{ $selectedSeats[$p->id] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if(!$totalSeats)
                        <!-- No numbered seat system for this trip (either not configured, or
                        the trip now has multiple buses and self-service selection can't safely
                        disambiguate which bus — Bus/Fleet redesign Ticket 2). Staff assign
                        seats manually instead. -->
                        <div class="max-w-md mx-auto bg-blue-50 border border-blue-100 text-blue-800 rounded-2xl p-8 text-center">
                            <span class="material-symbols-outlined text-4xl text-blue-400">event_seat</span>
                            <p class="mt-3 font-medium">سيتم تخصيص مقعدك من قبل فريق العمل</p>
                            <p class="mt-1 text-sm text-blue-600">لا حاجة لاختيار رقم مقعد لهذه الرحلة — يمكنك المتابعة مباشرة.</p>
                        </div>
                    @else
                    <!-- Legend -->
                    <div class="flex justify-center gap-6 text-sm mb-6 bg-slate-50 py-3 rounded-2xl border border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded bg-white border border-slate-300"></div>
                            <span class="text-slate-600">متاح</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded bg-blue-500 shadow-md"></div>
                            <span class="text-slate-600">محدد لك</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-5 rounded bg-slate-200 border border-slate-300 relative overflow-hidden">
                                <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPgo8cmVjdCB3aWR0aD0iNCIgaGVpZ2h0PSI0IiBmaWxsPSIjZmZmIiAvPgo8cGF0aCBkPSJNLTEsMSBsMiwtMiBNMCw0IGw0LC00IE0zLDUtIGwyLC0yIiBzdHJva2U9IiNlMmU4ZjAiIHN0cm9rZS13aWR0aD0iMSIgLz4KPC9zdmc+')] opacity-50"></div>
                            </div>
                            <span class="text-slate-600">محجوز</span>
                        </div>
                    </div>

                    <!-- Bus Grid (Simplified Top-Down view) -->
                    <div class="max-w-md mx-auto bg-slate-100 border border-slate-200 p-6 rounded-[3rem] shadow-inner relative">
                        <!-- Driver Area -->
                        <div class="flex justify-between items-end border-b-2 border-slate-300 pb-4 mb-6 relative">
                            <div class="w-12 h-12 bg-slate-200 rounded-full flex items-center justify-center opacity-50">
                                <span class="material-symbols-outlined text-slate-500 text-2xl">steering_wheel</span>
                            </div>
                            <!-- Door -->
                            <div class="absolute -right-6 top-2 w-2 h-16 bg-white border-y border-l border-slate-300 rounded-l"></div>
                        </div>

                        <!-- Seats Grid -->
                        <div class="grid grid-cols-4 gap-y-4 gap-x-2 relative">
                            <!-- Aisle marker -->
                            <div class="absolute top-0 bottom-0 left-1/2 -ml-3 w-6 border-x border-slate-200/50 bg-slate-200/20"></div>

                            @for($i = 1; $i <= $totalSeats; $i++)
                                @php
                                    $isAvailable = $availableSeats[$i];
                                    
                                    // Check if this seat is selected by ANY passenger in current session
                                    $isSelectedByMe = false;
                                    $selectedByPid = null;
                                    foreach ($selectedSeats as $pid => $seat) {
                                        if ($seat == $i) {
                                            $isSelectedByMe = true;
                                            $selectedByPid = $pid;
                                            break;
                                        }
                                    }

                                    // Aisle gap logic: column 3 adds a gap
                                    $colClass = '';
                                    if ($i % 4 == 3) $colClass = 'ml-6'; // Adds gap before 3rd seat
                                @endphp
                                
                                <div class="flex justify-center {{ $colClass }} relative z-10">
                                    @if($isAvailable)
                                        <!-- Need an Alpine component to handle the click and assign to next passenger without seat -->
                                        <button 
                                            wire:click="selectSeat('{{ collect($selectedSeats)->search(null) ?: collect($selectedSeats)->keys()->first() }}', {{ $i }})"
                                            class="w-12 h-12 rounded-t-xl rounded-b-md flex items-center justify-center text-sm font-bold transition-all transform hover:-translate-y-1 hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-blue-400
                                            {{ $isSelectedByMe ? 'bg-blue-600 text-white shadow-md border-b-4 border-blue-800' : 'bg-white text-slate-700 border-2 border-slate-300 hover:border-blue-400 border-b-4 hover:border-b-blue-600' }}"
                                            title="مقعد {{ $i }}"
                                        >
                                            {{ $i }}
                                        </button>
                                    @else
                                        <!-- Taken Seat -->
                                        <div class="w-12 h-12 rounded-t-xl rounded-b-md flex items-center justify-center text-sm font-bold bg-slate-200 text-slate-400 border-2 border-slate-300 cursor-not-allowed opacity-70 relative overflow-hidden">
                                            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPgo8cmVjdCB3aWR0aD0iNCIgaGVpZ2h0PSI0IiBmaWxsPSIjZmZmIiAvPgo8cGF0aCBkPSJNLTEsMSBsMiwtMiBNMCw0IGw0LC00IE0zLDUtIGwyLC0yIiBzdHJva2U9IiNlMmU4ZjAiIHN0cm9rZS13aWR0aD0iMSIgLz4KPC9zdmc+')] opacity-50"></div>
                                            <span class="relative z-10">{{ $i }}</span>
                                        </div>
                                    @endif
                                </div>
                            @endfor
                        </div>
                    </div>
                    @endif

                    <div class="flex justify-between mt-10">
                        <button wire:click="previousStep" class="px-6 py-3 border border-slate-300 text-slate-700 font-medium rounded-full hover:bg-slate-50 transition-colors focus:outline-none">
                            عودة
                        </button>
                        <button wire:click="nextStep" class="px-8 py-3 bg-green-600 text-white font-medium rounded-full shadow-md hover:bg-green-700 hover:shadow-lg transform hover:-translate-y-0.5 transition-all focus:outline-none flex items-center">
                            <span class="material-symbols-outlined ml-2">task_alt</span>
                            حفظ وتأكيد الحجز
                        </button>
                    </div>
                </div>
            @endif

            @if($step === 4)
                <!-- Step 4: Success -->
                <div class="text-center py-10 animate-fade-in-up">
                    <div class="w-24 h-24 mx-auto bg-green-100 text-green-600 rounded-full flex items-center justify-center mb-6 shadow-sm">
                        <span class="material-symbols-outlined text-6xl">check_circle</span>
                    </div>
                    <h2 class="text-3xl font-bold text-slate-800 mb-4">تم اكتمال بياناتك بنجاح! 🎉</h2>
                    <p class="text-lg text-slate-600 mb-8 max-w-lg mx-auto">
                        لقد قمنا بحفظ بيانات جميع الركاب وتثبيت أرقام المقاعد في الحافلة. يمكنك الآن إغلاق هذه الصفحة والبدء بتجهيز حقائبك للرحلة!
                    </p>
                    
                    <a href="{{ route('customer.ticket.download', ['uuid' => $uuid]) }}" target="_blank" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold rounded-full shadow-lg hover:shadow-xl transform hover:-translate-y-1 transition-all mb-8">
                        <span class="material-symbols-outlined ml-2 text-2xl">download</span>
                        تحميل تذكرة الرحلة (E-Ticket)
                    </a>
                    
                    <div class="bg-blue-50 border border-blue-100 p-4 rounded-2xl inline-block text-right mb-8 w-full max-w-lg">
                        <p class="text-blue-800 font-bold mb-2">تذكير:</p>
                        <ul class="list-disc list-inside text-blue-700 text-sm space-y-1">
                            <li>نقطة التجمع ستكون حسب ما تم الاتفاق عليه عبر الهاتف.</li>
                            <li>يرجى التواجد قبل موعد الانطلاق بـ 15 دقيقة على الأقل.</li>
                            <li>لا تنسَ إحضار الوثائق الأصلية التي تم رفع صورها.</li>
                        </ul>
                    </div>

                    <div>
                        <a href="/" class="inline-flex items-center px-6 py-3 border border-slate-300 text-slate-700 font-medium rounded-full hover:bg-slate-50 transition-colors">
                            العودة للرئيسية
                        </a>
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
