<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

    @if ($successBooking)
        <!-- Success State (Rule UX-4) -->
        <div class="max-w-2xl mx-auto bg-white rounded-3xl p-8 border border-gray-100 shadow-xl text-center">
            <div class="w-20 h-20 bg-primary-50 rounded-full flex items-center justify-center mx-auto mb-6 text-primary-600">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            
            <h1 class="text-3xl font-black text-gray-900 mb-2">تم استلام طلب الحجز بنجاح!</h1>
            <p class="text-gray-500 mb-8">نشكرك على اختيارك وكالة {{ $tenant->name }}</p>

            <!-- Booking Reference Card -->
            <div class="bg-gray-50 rounded-2xl p-6 mb-8 border border-gray-100">
                <span class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">رقم مرجع الحجز (قابل للنسخ)</span>
                <div class="flex items-center justify-center gap-3">
                    <span class="text-3xl font-black text-primary-700 select-all" id="booking-ref">{{ $successBooking->reference }}</span>
                    <button onclick="navigator.clipboard.writeText('{{ $successBooking->reference }}'); alert('تم نسخ رقم المرجع!');" 
                            class="p-2 text-gray-400 hover:text-primary-600 hover:bg-white rounded-lg transition-colors border border-transparent hover:border-gray-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Booking Details Summary -->
            <div class="border-t border-b border-gray-100 py-6 mb-8 text-right space-y-3">
                <h3 class="font-bold text-gray-800">تفاصيل الرحلة المحجوزة:</h3>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">اسم الرحلة:</span>
                    <span class="font-semibold text-gray-800">{{ $tripInstance->tripTemplate->title }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">تاريخ الذهاب:</span>
                    <span class="font-semibold text-gray-800">{{ $tripInstance->start_date->format('Y-m-d') }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">عدد المسافرين:</span>
                    <span class="font-semibold text-gray-800">{{ $successBooking->passengers()->count() }} مسافرين</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">إجمالي المبلغ المطلوب:</span>
                    <span class="font-bold text-primary-700">${{ number_format($successBooking->total_amount, 2) }}</span>
                </div>
            </div>

            <!-- What happens next -->
            <div class="bg-primary-50/50 border border-primary-100/50 rounded-2xl p-6 text-right mb-8">
                <h4 class="font-bold text-primary-800 mb-2">ماذا سيحدث بعد ذلك؟</h4>
                <ul class="text-sm text-primary-950 space-y-2 list-disc list-inside">
                    <li>حالة حجزك الآن هي <strong>(معلق)</strong> بانتظار سداد الدفعة الأولى.</li>
                    <li>لقد أرسلنا لك رسالة لتأكيد الحجز وتفاصيل السداد عبر الواتساب.</li>
                    <li>بمجرد إتمام الدفع وتأكيد الحجز من قبل الوكيل، ستتحول حالة حجزك إلى <strong>(مؤكد)</strong>.</li>
                    <li>عندها ستتمكن من تحميل تذاكر الطيران، وقسيمة الفندق، والتأمين مباشرة من خلال بوابتك الإلكترونية.</li>
                </ul>
            </div>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                @php
                    $shareText = "مرحباً، لقد قمت للتو بحجز رحلة {$tripInstance->tripTemplate->title} برقم مرجعي {$successBooking->reference} عبر نظام زاتارا للسياحة.";
                    $whatsappUrl = "https://wa.me/?text=" . urlencode($shareText);
                @endphp
                <a href="{{ $whatsappUrl }}" target="_blank" 
                   class="inline-flex items-center justify-center gap-2 px-6 h-12 text-sm font-bold text-white bg-[#25D366] hover:bg-[#20ba59] rounded-2xl transition-colors">
                    <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24">
                        <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.514 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.713-1.455L0 24zm6.273-3.838l.409.243c1.517.9 3.424 1.376 5.32 1.378 5.792 0 10.502-4.677 10.505-10.428.002-2.785-1.077-5.405-3.037-7.37C17.567 2.054 14.95 9.78 12.008 9.78c-5.79 0-10.5 4.678-10.503 10.43-.001 1.81.478 3.578 1.388 5.102l.266.446-1.002 3.66 3.766-.988zm12.335-8.243c-.328-.164-1.942-.958-2.242-1.068-.3-.11-.518-.164-.736.164-.218.327-.843 1.068-1.034 1.285-.19.219-.382.246-.71.082-.328-.164-1.386-.51-2.64-1.627-.975-.87-1.633-1.946-1.824-2.274-.19-.328-.02-.505.144-.668.148-.146.328-.382.492-.574.164-.19.219-.328.328-.546.11-.219.055-.41-.027-.574-.082-.164-.736-1.775-1.009-2.43-.266-.638-.535-.552-.736-.563-.19-.01-.41-.01-.628-.01-.218 0-.573.082-.873.41-.3.327-1.145 1.118-1.145 2.732 0 1.614 1.173 3.167 1.337 3.385.164.218 2.308 3.525 5.59 4.945.78.337 1.39.54 1.868.692.784.248 1.498.213 2.062.129.629-.094 1.942-.793 2.215-1.558.272-.764.272-1.42.19-1.558-.082-.137-.3-.219-.628-.382z"/>
                    </svg>
                    مشاركة رقم الحجز عبر الواتساب
                </a>
                
                <a href="{{ route('portal.dashboard', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" 
                   class="inline-flex items-center justify-center px-6 h-12 text-sm font-bold text-primary-700 bg-primary-50 hover:bg-primary-100 rounded-2xl transition-colors">
                    الذهاب إلى بوابة وثائق السفر
                </a>
            </div>
        </div>
    @else
        <!-- Checkout Layout -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Right side: Steps Form (2/3 width on desktop) -->
            <div class="lg:col-span-2 space-y-8 text-right">
                
                <!-- Heading -->
                <div class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm flex items-center gap-4">
                    <div class="w-12 h-12 bg-primary-50 rounded-2xl flex items-center justify-center text-primary-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900">تأكيد حجز لرحلة: {{ $tripInstance->tripTemplate->title }}</h1>
                        <p class="text-xs text-gray-500 mt-0.5">تاريخ الذهاب: {{ $tripInstance->start_date->format('Y-m-d') }}</p>
                    </div>
                </div>

                @if(session()->has('booking_error'))
                    <div class="bg-red-50 border border-red-100 text-red-700 px-4 py-3 rounded-2xl text-sm">
                        {{ session('booking_error') }}
                    </div>
                @endif

                <!-- STEP 1: Phone Verification (Rule UX-1) -->
                <div class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm space-y-6">
                    <div class="flex items-center gap-3 border-b border-gray-50 pb-4">
                        <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-bold text-sm">1</div>
                        <h2 class="font-bold text-gray-800">بيانات الاتصال والتحقق</h2>
                    </div>

                    @if ($isVerified)
                        <!-- Verified Badge -->
                        <div class="bg-primary-50 border border-primary-100 rounded-2xl p-4 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="w-6 h-6 rounded-full bg-primary-500 text-white flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-primary-900">{{ $name }}</p>
                                    <p class="text-xs text-primary-700" dir="ltr">{{ $phone }}</p>
                                </div>
                            </div>
                            <span class="text-xs font-semibold bg-primary-200/50 text-primary-800 px-3 py-1 rounded-full">مُحقّق</span>
                        </div>
                    @else
                        <!-- Contact Fields -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">الاسم الكامل (صاحب الحجز) *</label>
                                <input type="text" wire:model="name" placeholder="أدخل اسمك الثلاثي"
                                       class="w-full h-12 px-4 rounded-xl border border-gray-200 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 outline-none text-sm transition-all @error('name') border-red-300 @enderror">
                                @error('name') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1.5">رقم الهاتف الجوال *</label>
                                <input type="tel" wire:model="phone" placeholder="+970 59 XXX XXXX" dir="ltr"
                                       class="w-full h-12 px-4 rounded-xl border border-gray-200 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 outline-none text-sm transition-all text-left @error('phone') border-red-300 @enderror">
                                @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <!-- OTP Section -->
                        @if ($showOtpInput)
                            <div class="bg-primary-50/50 border border-primary-100/50 rounded-2xl p-4 space-y-4">
                                <div class="text-xs text-primary-800 font-medium">
                                    {{ $verificationSuccessMessage }}
                                </div>
                                <div class="max-w-xs">
                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">رمز التحقق (OTP) *</label>
                                    <div class="flex gap-3">
                                        <input type="text" wire:model="otp" placeholder="XXXX" dir="ltr"
                                               class="w-24 h-12 text-center rounded-xl border border-gray-200 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 outline-none text-lg font-bold transition-all @error('otp') border-red-300 @enderror">
                                        <button type="button" wire:click="verifyCode"
                                                class="flex-grow bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold text-sm px-4 h-12 transition-colors">
                                            تأكيد الرمز
                                        </button>
                                    </div>
                                    @error('otp') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                    @if ($verificationError) <span class="text-xs text-red-500 mt-1 block">{{ $verificationError }}</span> @endif
                                </div>
                                <div class="bg-amber-50 border border-amber-100 text-amber-800 rounded-xl p-3 text-xs leading-relaxed">
                                    <strong>لأغراض تجريبية:</strong> رمز التحقق هو <strong>1234</strong> (أو يمكنك مراجعة سجلات النظام لمعرفة الرمز العشوائي).
                                </div>
                            </div>
                        @else
                            <button type="button" wire:click="sendVerificationCode"
                                    class="w-full bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold text-sm h-12 transition-colors">
                                إرسال رمز التحقق (OTP)
                            </button>
                            @if ($verificationError) <span class="text-xs text-red-500 mt-1 block">{{ $verificationError }}</span> @endif
                        @endif
                    @endif
                </div>

                <!-- STEP 2: Passenger Details & Media Uploads (Rule UX-2) -->
                <div class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm space-y-6 @if(!$isVerified) opacity-50 pointer-events-none @endif">
                    <div class="flex items-center justify-between border-b border-gray-50 pb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-bold text-sm">2</div>
                            <h2 class="font-bold text-gray-800">تفاصيل المسافرين والوثائق</h2>
                        </div>
                        
                        @if ($isVerified)
                            <div class="flex items-center gap-2">
                                <label class="text-xs text-gray-500 font-bold">عدد المسافرين:</label>
                                <select wire:model.live="passengerCount" class="h-9 px-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white">
                                    @for($i=1; $i<=10; $i++)
                                        <option value="{{ $i }}">{{ $i }}</option>
                                    @endfor
                                </select>
                            </div>
                        @endif
                    </div>

                    @if (!$isVerified)
                        <div class="text-center py-6 text-gray-400 text-sm">
                            🔒 يرجى إتمام التحقق من رقم الهاتف لفتح هذا القسم وتعبئة بيانات الجوازات والمسافرين.
                        </div>
                    @else
                        <!-- Passenger Cards -->
                        <div class="space-y-6">
                            @foreach ($passengers as $index => $px)
                                <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/50 space-y-4">
                                    <h3 class="font-bold text-xs text-primary-700 uppercase">المسافر #{{ $index + 1 }}</h3>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-bold text-gray-700 mb-1">الاسم الكامل (مطابق للجواز) *</label>
                                            <input type="text" wire:model="passengers.{{ $index }}.name" placeholder="اسم المسافر"
                                                   class="w-full h-10 px-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white">
                                            @error("passengers.{$index}.name") <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-gray-700 mb-1">رقم جواز السفر *</label>
                                            <input type="text" wire:model="passengers.{{ $index }}.passport_number" placeholder="رقم الجواز"
                                                   class="w-full h-10 px-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white uppercase">
                                            @error("passengers.{$index}.passport_number") <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-bold text-gray-700 mb-1">متطلبات خاصة (اختياري)</label>
                                        <textarea wire:model="passengers.{{ $index }}.special_requirements" rows="2" placeholder="متطلبات مثل وجبة معينة، مقعد محدد، مساعدة طبية..."
                                                  class="w-full p-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white resize-none"></textarea>
                                    </div>

                                    <!-- Media Uploads (passport / national ID) -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                                        <div>
                                            <label class="block text-xs font-bold text-gray-700 mb-1">صورة جواز السفر * (صورة أو PDF)</label>
                                            <input type="file" wire:model="passengers.{{ $index }}.passport_photo" class="text-xs text-gray-500 file:ms-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                                            <div wire:loading wire:target="passengers.{{ $index }}.passport_photo" class="text-xs text-primary-600 mt-1">جاري الرفع مؤقتاً...</div>
                                            @error("passengers.{$index}.passport_photo") <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-gray-700 mb-1">صورة الهوية الوطنية (اختياري)</label>
                                            <input type="file" wire:model="passengers.{{ $index }}.national_id_photo" class="text-xs text-gray-500 file:ms-4 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                                            <div wire:loading wire:target="passengers.{{ $index }}.national_id_photo" class="text-xs text-primary-600 mt-1">جاري الرفع مؤقتاً...</div>
                                            @error("passengers.{$index}.national_id_photo") <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- STEP 3: Travel Extras (Requests flight/hotel/insurance) -->
                <div class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm space-y-6 @if(!$isVerified) opacity-50 pointer-events-none @endif">
                    <div class="flex items-center gap-3 border-b border-gray-50 pb-4">
                        <div class="w-8 h-8 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-bold text-sm">3</div>
                        <h2 class="font-bold text-gray-800">الخدمات الإضافية والترتيبات الخاصة</h2>
                    </div>

                    @if (!$isVerified)
                        <div class="text-center py-6 text-gray-400 text-sm">
                            🔒 يرجى إتمام التحقق لفتح الخدمات الإضافية.
                        </div>
                    @else
                        <p class="text-xs text-gray-500 leading-relaxed">
                            إذا كنت بحاجة إلى حجز تذاكر الطيران، الفندق، التأمين، أو تأشيرة السفر، يرجى تفعيل الخيارات أدناه وتزويدنا بالتفاصيل. سنقوم برفع الوثائق والمستندات لك مباشرة فور تأكيدها.
                        </p>
                        
                        <div class="space-y-4">
                            <!-- Flight extra -->
                            <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/30">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" wire:model.live="needsFlight" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                                    <span class="font-bold text-sm text-gray-800">حجز تذكرة الطيران</span>
                                </label>
                                @if($needsFlight)
                                    <div class="mt-3">
                                        <textarea wire:model="flightDetails" rows="2" placeholder="الرجاء ذكر تفاصيل الطيران المطلوبة (مثال: ذهاب فقط، درجة رجال أعمال، تفضيل شركة طيران معينة...)"
                                                  class="w-full p-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white resize-none"></textarea>
                                    </div>
                                @endif
                            </div>

                            <!-- Hotel extra -->
                            <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/30">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" wire:model.live="needsHotel" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                                    <span class="font-bold text-sm text-gray-800">حجز الفندق</span>
                                </label>
                                @if($needsHotel)
                                    <div class="mt-3">
                                        <textarea wire:model="hotelDetails" rows="2" placeholder="الرجاء ذكر تفاصيل الفندق والغرف المطلوبة (مثال: غرفة مزدوجة، إطلالة على البحر، فئة 5 نجوم...)"
                                                  class="w-full p-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white resize-none"></textarea>
                                    </div>
                                @endif
                            </div>

                            <!-- Insurance extra -->
                            <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/30">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" wire:model.live="needsInsurance" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                                    <span class="font-bold text-sm text-gray-800">تأمين السفر</span>
                                </label>
                                @if($needsInsurance)
                                    <div class="mt-3">
                                        <textarea wire:model="insuranceDetails" rows="2" placeholder="أدخل متطلبات تأمين السفر (تأمين شامل، تأمين طبي، مدة التأمين...)"
                                                  class="w-full p-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white resize-none"></textarea>
                                    </div>
                                @endif
                            </div>

                            <!-- Visa extra (Only if enabled on tenant) -->
                            @if ($tenant->is_visa_enabled)
                                <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/30">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" wire:model.live="needsVisa" class="w-4 h-4 text-primary-600 border-gray-300 rounded focus:ring-primary-500">
                                        <span class="font-bold text-sm text-gray-800">إصدار تأشيرة السفر (فيزا)</span>
                                    </label>
                                    @if($needsVisa)
                                        <div class="mt-3">
                                            <textarea wire:model="visaDetails" rows="2" placeholder="تفاصيل التأشيرة المطلوبة والمستندات الجاهزة لديك..."
                                                      class="w-full p-3 rounded-lg border border-gray-200 focus:border-primary-500 outline-none text-xs bg-white resize-none"></textarea>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($isVerified)
                    <!-- Submission Button -->
                    <button type="button" wire:click="submitBooking"
                            class="w-full bg-primary-600 hover:bg-primary-700 text-white rounded-2xl font-bold h-14 transition-colors shadow-lg shadow-primary-200 flex items-center justify-center gap-2">
                        <span>إرسال وتأكيد طلب الحجز</span>
                        <div wire:loading wire:target="submitBooking" class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                    </button>
                @endif
            </div>

            <!-- Left side: Summary Panel (1/3 width, Rule UX-3: Sticky price always visible) -->
            <div class="space-y-6 text-right">
                <div class="bg-white rounded-3xl p-6 border border-gray-100 shadow-sm sticky top-24">
                    <h3 class="font-bold text-gray-900 mb-4 border-b border-gray-50 pb-3">ملخص الحساب</h3>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">سعر الفرد:</span>
                            <span class="font-bold text-gray-800">
                                ${{ number_format($tripInstance->price_override ?? $tripInstance->tripTemplate->base_price, 2) }}
                            </span>
                        </div>
                        
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">عدد المسافرين:</span>
                            <span class="font-bold text-gray-800">{{ $passengerCount }}</span>
                        </div>

                        <!-- Extra summaries -->
                        @if($needsFlight || $needsHotel || $needsInsurance || $needsVisa)
                            <div class="border-t border-gray-50 pt-3">
                                <span class="block text-xs font-bold text-gray-400 mb-2">الخدمات الإضافية المطلوبة:</span>
                                <div class="space-y-1">
                                    @if($needsFlight) <span class="inline-block bg-primary-50 text-primary-700 text-xs px-2.5 py-1 rounded-lg font-medium ms-1">تذكرة طيران</span> @endif
                                    @if($needsHotel) <span class="inline-block bg-primary-50 text-primary-700 text-xs px-2.5 py-1 rounded-lg font-medium ms-1">حجز فندق</span> @endif
                                    @if($needsInsurance) <span class="inline-block bg-primary-50 text-primary-700 text-xs px-2.5 py-1 rounded-lg font-medium ms-1">تأمين سفر</span> @endif
                                    @if($needsVisa && $tenant->is_visa_enabled) <span class="inline-block bg-primary-50 text-primary-700 text-xs px-2.5 py-1 rounded-lg font-medium ms-1">تأشيرة</span> @endif
                                </div>
                            </div>
                        @endif

                        <!-- Total price -->
                        <div class="border-t border-gray-100 pt-4 flex justify-between items-center">
                            <span class="text-gray-700 font-bold">الإجمالي الكلي:</span>
                            @php
                                $pricePerSeat = $this->tripInstance->price_override ?? $this->tripInstance->tripTemplate->base_price;
                                $calculatedTotal = $pricePerSeat * $this->passengerCount;
                            @endphp
                            <span class="text-3xl font-black text-primary-700">${{ number_format($calculatedTotal, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    @endif

</div>
