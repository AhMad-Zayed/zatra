<div dir="rtl" class="min-h-screen bg-[#fcf8f8] font-arabic text-slate-800 py-20" x-data="{ step: $wire.entangle('currentStep') }">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header & Stepper -->
        <div class="mb-12 text-center">
            <h1 class="text-4xl font-bold text-zatara-blue tracking-tight mb-2">
                تأكيد حجز رحلتك
            </h1>
            <p class="text-slate-500 text-lg">
                {{ $tripInstance->tripTemplate->title }}
            </p>

            <!-- Stepper Progress -->
            <div class="mt-10 flex justify-center items-center gap-2 max-w-2xl mx-auto" x-show="step < 6">
                <template x-for="i in 5" :key="i">
                    <div class="flex items-center">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold transition-all duration-500 shadow-sm"
                             :class="{
                                 'bg-zatara-blue text-white shadow-lg shadow-zatara-blue/30': step >= i,
                                 'bg-white text-slate-400 border border-slate-200': step < i
                             }">
                            <span x-text="i"></span>
                        </div>
                        <div class="w-8 sm:w-16 h-1 transition-all duration-500" x-show="i < 5"
                             :class="{
                                 'bg-zatara-blue': step > i,
                                 'bg-slate-200': step <= i
                             }">
                        </div>
                    </div>
                </template>
            </div>
        </div>

        @if($this->guestSession)
            <div class="max-w-4xl mx-auto mb-6 bg-orange-100 text-orange-800 p-4 rounded-xl text-center font-bold"
                 x-data="{ 
                     expiresAt: new Date('{{ $this->guestSession->expires_at->toIso8601String() }}').getTime(),
                     now: new Date().getTime(),
                     distance: 0,
                     minutes: 0,
                     seconds: 0,
                     startTimer() {
                         setInterval(() => {
                             this.now = new Date().getTime();
                             this.distance = this.expiresAt - this.now;
                             if (this.distance < 0) {
                                 window.location.href = '{{ route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripInstance' => $tripInstance->id]) }}?expired=1';
                             } else {
                                 this.minutes = Math.floor((this.distance % (1000 * 60 * 60)) / (1000 * 60));
                                 this.seconds = Math.floor((this.distance % (1000 * 60)) / 1000);
                             }
                         }, 1000);
                     }
                 }"
                 x-init="startTimer()">
                ⏱ مقاعدك محجوزة مؤقتاً لمدة: 
                <span x-text="minutes"></span>:<span x-text="seconds < 10 ? '0' + seconds : seconds"></span>
            </div>
        @endif

        <!-- Form Wrapper (Glassmorphism) -->
        <div class="glass-panel rounded-[2.5rem] relative min-h-[400px] p-8 sm:p-12 overflow-hidden border-t border-white/60">
            
            <!-- Global Loading Indicator -->
            <div wire:loading class="absolute inset-0 bg-white/80 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
                <svg class="animate-spin h-12 w-12 text-zatara-blue mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-zatara-blue font-bold animate-pulse text-lg">جاري المعالجة...</p>
            </div>

            <!-- STEP 1: Lead Capture -->
            <div x-show="step === 1" x-transition.opacity.duration.300ms class="space-y-8">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-zatara-blue">مرحباً بك في زتارة</h2>
                    <p class="text-slate-500 text-base mt-2">يرجى إدخال بياناتك الأساسية للبدء بإجراءات الحجز</p>
                </div>

                <form wire:submit.prevent="submitLeadCapture" class="max-w-md mx-auto space-y-6">
                    <div>
                        <label for="first_name" class="block text-sm font-bold text-zatara-blue mb-2">الاسم الأول</label>
                        <input type="text" id="first_name" wire:model="form.passengers.0.first_name" placeholder="محمد"
                               class="glass-input w-full px-4 py-4 text-slate-800 text-lg">
                        @error('form.passengers.0.first_name') <span class="text-zatara-red text-xs mt-2 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-bold text-zatara-blue mb-2">البريد الإلكتروني</label>
                        <input type="email" id="email" wire:model="form.email" dir="ltr" placeholder="example@gmail.com"
                               class="glass-input w-full px-4 py-4 text-slate-800 text-lg">
                        @error('form.email') <span class="text-zatara-red text-xs mt-2 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-bold text-zatara-blue mb-2">رقم الجوال <span class="text-slate-400 font-normal">(اختياري للواتساب)</span></label>
                        <input type="text" id="phone" wire:model="form.phone" dir="ltr" placeholder="+966500000000"
                               class="glass-input w-full px-4 py-4 text-slate-800 text-lg">
                        @error('form.phone') <span class="text-zatara-red text-xs mt-2 block font-medium">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" class="btn-primary w-full shadow-lg shadow-zatara-blue/20">
                        متابعة
                    </button>
                </form>
            </div>

            <!-- STEP 2: OTP -->
            <div x-show="step === 2" 
                 x-transition:enter="transition ease-out duration-500"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 style="display: none;" class="space-y-8">
                <div class="text-center mb-10">
                    <div class="w-16 h-16 bg-zatara-blue/5 text-zatara-blue rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-[32px]">sms</span>
                    </div>
                    <h2 class="text-3xl font-bold text-zatara-blue">رمز التحقق</h2>
                    <p class="text-slate-500 text-base mt-2">لقد أرسلنا رمزاً مكوناً من 6 أرقام إلى هاتفك</p>
                </div>

                <form wire:submit.prevent="verifyOtp" class="max-w-md mx-auto space-y-8">
                    <div>
                        <input type="text" id="otp" wire:model="form.otp" maxlength="6" dir="ltr" placeholder="123456"
                               class="glass-input w-full text-center tracking-[0.5em] text-3xl font-bold text-zatara-blue py-4 transition-all duration-300 focus:ring-2 {{ $errors->has('form.otp') ? 'border-zatara-red focus:ring-zatara-red/20' : 'focus:ring-zatara-blue/20' }}">
                        @error('form.otp') <span class="text-zatara-red text-xs mt-2 block text-center font-medium">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex gap-4">
                        <button type="button" wire:click="$set('currentStep', 1)" class="w-1/3 bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-4 rounded-2xl transition-all">
                            تراجع
                        </button>
                        <button type="submit" class="btn-primary w-2/3 shadow-lg shadow-zatara-blue/20 relative disabled:opacity-75" wire:loading.attr="disabled" wire:target="verifyOtp">
                            <span wire:loading.remove wire:target="verifyOtp">تحقق</span>
                            <span wire:loading wire:target="verifyOtp" class="flex items-center justify-center gap-2">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                جاري التحقق...
                            </span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- STEP 3: Passengers -->
            <div x-show="step === 3" 
                 x-transition:enter="transition ease-out duration-500 delay-100"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 style="display: none;" class="space-y-8">
                <div class="mb-8 border-b border-slate-100 pb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-zatara-blue">بيانات المسافرين</h2>
                        <p class="text-slate-500 text-sm mt-1">يرجى كتابة الأسماء مطابقة لجواز السفر.</p>
                    </div>
                    @error('form.passengers') 
                        <div class="p-3 bg-zatara-red/10 border border-zatara-red/20 rounded-xl text-zatara-red text-sm font-medium animate-pulse">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                @if(auth('customer')->check())
                    <div class="mb-6 p-4 bg-zatara-blue/5 border border-zatara-blue/20 rounded-2xl flex items-center gap-3">
                        <span class="material-symbols-outlined text-zatara-blue text-2xl">magic_button</span>
                        <p class="text-zatara-blue font-bold">✨ أهلاً بك مجدداً يا {{ auth('customer')->user()->name }}، لقد قمنا بتسريع خطوات الحجز من أجلك!</p>
                    </div>
                @endif
                        <div class="p-3 bg-zatara-red/10 border border-zatara-red/20 rounded-xl text-zatara-red text-sm font-medium animate-pulse">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <form wire:submit.prevent="submitPassengers" class="space-y-8">
                    @foreach($form->passengers as $index => $passenger)
                        <div wire:key="passenger-item-{{ $index }}" class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm relative group transition-all hover:shadow-md">
                            <div class="absolute top-6 left-6">
                                @if(count($form->passengers) > 1)
                                    <button type="button" wire:click="removePassenger({{ $index }})" class="w-8 h-8 rounded-full bg-slate-50 text-slate-400 hover:bg-zatara-red/10 hover:text-zatara-red transition-all flex items-center justify-center">
                                        <span class="material-symbols-outlined text-[18px]">close</span>
                                    </button>
                                @endif
                            </div>
                            
                            <div class="flex items-center justify-between mb-6">
                                <h4 class="text-zatara-gold font-bold text-lg flex items-center gap-2">
                                    <span class="material-symbols-outlined">person</span>
                                    مسافر #{{ $index + 1 }}
                                </h4>
                                @if($index === 0 && auth('customer')->check())
                                    <button type="button" wire:click="autoFillPassenger" class="text-xs bg-zatara-blue/10 text-zatara-blue hover:bg-zatara-blue hover:text-white transition-all px-4 py-2 rounded-full font-bold flex items-center gap-1 shadow-sm">
                                        <span class="material-symbols-outlined text-[16px]">account_circle</span>
                                        👤 أنا أحد الركاب
                                    </button>
                                @endif
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">الاسم الأول</label>
                                    <input type="text" wire:model="form.passengers.{{ $index }}.first_name" placeholder="الاسم الأول"
                                           class="glass-input w-full px-4 py-3 text-slate-800 transition-colors {{ $errors->has("form.passengers.{$index}.first_name") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                    @error("form.passengers.{$index}.first_name") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">اسم العائلة</label>
                                    <input type="text" wire:model="form.passengers.{{ $index }}.last_name" placeholder="اسم العائلة"
                                           class="glass-input w-full px-4 py-3 text-slate-800 transition-colors {{ $errors->has("form.passengers.{$index}.last_name") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                    @error("form.passengers.{$index}.last_name") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">تاريخ الميلاد <span class="text-slate-400 font-normal">(اختياري)</span></label>
                                    <input type="date" wire:model="form.passengers.{{ $index }}.date_of_birth"
                                           class="glass-input w-full px-4 py-3 text-slate-800 transition-colors {{ $errors->has("form.passengers.{$index}.date_of_birth") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                    @error("form.passengers.{$index}.date_of_birth") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">نوع الوثيقة <span class="text-slate-400 font-normal">(اختياري)</span></label>
                                    <select wire:model="form.passengers.{{ $index }}.document_type"
                                            class="glass-input w-full px-4 py-3 text-slate-800 bg-white transition-colors {{ $errors->has("form.passengers.{$index}.document_type") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                        <option value="">اختر النوع...</option>
                                        <option value="national_id">هوية وطنية</option>
                                        <option value="passport">جواز سفر</option>
                                    </select>
                                    @error("form.passengers.{$index}.document_type") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">رقم الوثيقة <span class="text-slate-400 font-normal">(اختياري)</span></label>
                                    <input type="text" wire:model="form.passengers.{{ $index }}.document_number" dir="ltr" placeholder="رقم الهوية/الجواز"
                                           class="glass-input w-full px-4 py-3 text-slate-800 transition-colors {{ $errors->has("form.passengers.{$index}.document_number") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                    @error("form.passengers.{$index}.document_number") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-zatara-blue mb-2">نوع المسافر (الباقة)</label>
                                    <select wire:model="form.passengers.{{ $index }}.trip_passenger_category_id"
                                            class="glass-input w-full px-4 py-3 text-slate-800 bg-white transition-colors {{ $errors->has("form.passengers.{$index}.trip_passenger_category_id") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                        <option value="">اختر الباقة...</option>
                                        @foreach($tripInstance->tripPassengerCategories ?? [] as $tier)
                                            <option value="{{ $tier->id }}">{{ $tier->name }} ({{ number_format($tier->price) }} دولار)</option>
                                        @endforeach
                                    </select>
                                    @error("form.passengers.{$index}.trip_passenger_category_id") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                </div>
                                
                                @if($this->availablePickupPoints->count() > 0)
                                    <div>
                                        <label class="block text-xs font-bold text-zatara-blue mb-2">نقطة التجمع <span class="text-slate-400 font-normal">(اختياري)</span></label>
                                        <select wire:model="form.passengers.{{ $index }}.pickup_point_id"
                                                class="glass-input w-full px-4 py-3 text-slate-800 bg-white transition-colors {{ $errors->has("form.passengers.{$index}.pickup_point_id") ? 'border-zatara-red bg-zatara-red/5 focus:ring-zatara-red/20' : '' }}">
                                            <option value="">لا يوجد/تجمع ذاتي</option>
                                            @foreach($this->availablePickupPoints as $point)
                                                <option value="{{ $point->id }}">{{ $point->name }} - {{ \Carbon\Carbon::parse($point->pickup_time)->format('h:i A') }}</option>
                                            @endforeach
                                        </select>
                                        @error("form.passengers.{$index}.pickup_point_id") <span class="text-zatara-red text-xs mt-1 block font-bold">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <button type="button" wire:click="addPassenger" class="w-full border-2 border-dashed border-zatara-blue/20 text-zatara-blue hover:bg-zatara-blue/5 hover:border-zatara-blue/40 font-bold py-4 rounded-3xl transition-all flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">person_add</span>
                        إضافة مسافر آخر
                    </button>

                    <div class="flex justify-end pt-6 border-t border-slate-100 mt-8">
                        <button type="submit" class="btn-primary shadow-lg shadow-zatara-blue/20 px-12 py-4 text-lg">
                            متابعة للإضافات
                        </button>
                    </div>
                </form>
            </div>

            <!-- STEP 4: Addons -->
            <div x-show="step === 4" x-transition.opacity.duration.300ms style="display: none;" class="space-y-8">
                <div class="mb-8 border-b border-slate-100 pb-6">
                    <h2 class="text-2xl font-bold text-zatara-blue">الإضافات الاختيارية</h2>
                    <p class="text-slate-500 text-sm mt-1">عزز تجربتك بإضافة هذه الخدمات المميزة.</p>
                </div>

                <form wire:submit.prevent="submitAddons" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @forelse($tripInstance->addons ?? [] as $addon)
                            <label class="relative flex flex-col p-6 bg-white/40 backdrop-blur-md border border-white/60 rounded-3xl cursor-pointer hover:border-zatara-gold hover:shadow-xl hover:-translate-y-1 transition-all duration-300 has-[:checked]:border-zatara-gold has-[:checked]:bg-gradient-to-br has-[:checked]:from-zatara-gold/10 has-[:checked]:to-transparent overflow-hidden group">
                                <input type="checkbox" wire:click="toggleAddon({{ $addon->id }})" 
                                       @if(collect($form->addons)->contains('trip_addon_id', $addon->id)) checked @endif
                                       class="absolute top-6 left-6 w-6 h-6 rounded-lg border-slate-300 text-zatara-gold focus:ring-zatara-gold/50 cursor-pointer">
                                
                                <div class="flex items-start gap-4 mb-4">
                                    <div class="w-12 h-12 rounded-full bg-zatara-blue/5 flex items-center justify-center text-zatara-blue group-has-[:checked]:bg-zatara-gold group-has-[:checked]:text-white transition-colors">
                                        <span class="material-symbols-outlined text-[24px]">verified</span>
                                    </div>
                                    <div>
                                        <span class="block font-bold text-zatara-blue text-lg">{{ $addon->name }}</span>
                                        <span class="block text-sm text-slate-500 mt-1 leading-relaxed">{{ $addon->description ?? 'إضافة رائعة تمنحك المزيد من الراحة والرفاهية خلال رحلتك.' }}</span>
                                    </div>
                                </div>
                                
                                <div class="mt-auto pt-4 border-t border-slate-100 group-has-[:checked]:border-zatara-gold/20 flex items-center justify-between">
                                    <span class="text-sm font-bold text-slate-400 group-has-[:checked]:text-zatara-gold">التكلفة الإضافية</span>
                                    <div class="font-black text-zatara-blue group-has-[:checked]:text-zatara-gold text-2xl">
                                        +{{ number_format($addon->price) }} $
                                    </div>
                                </div>
                            </label>
                        @empty
                            <div class="col-span-1 md:col-span-2 text-center py-16 bg-slate-50/50 backdrop-blur-sm rounded-3xl border border-dashed border-slate-200">
                                <span class="material-symbols-outlined text-5xl text-slate-300 mb-4 block">category</span>
                                <p class="text-slate-500 font-bold text-lg">لا توجد إضافات متاحة لهذه الرحلة حالياً.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="flex justify-between pt-6 border-t border-slate-100 mt-10">
                        <button type="button" wire:click="$set('currentStep', 3)" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-4 px-8 rounded-2xl transition-all">
                            السابق
                        </button>
                        <button type="submit" class="btn-primary shadow-lg shadow-zatara-blue/20 px-12 py-4 text-lg">
                            متابعة للدفع
                        </button>
                    </div>
                </form>
            </div>

            <!-- STEP 5: Payment Method & Summary -->
            <div x-show="step === 5" x-transition.opacity.duration.300ms style="display: none;" class="space-y-8">
                <div class="mb-8 border-b border-slate-100 pb-6">
                    <h2 class="text-2xl font-bold text-zatara-blue">طريقة الدفع والتأكيد</h2>
                    <p class="text-slate-500 text-sm mt-1">اختر طريقة الدفع المناسبة لك لإتمام الحجز.</p>
                </div>

                <form wire:submit.prevent="submitBooking" class="space-y-8">
                    
                    <!-- Payment Type Selection (Full vs Deposit) -->
                    @if($tripInstance->tripTemplate->deposit_enabled)
                        <div class="mb-8">
                            <h3 class="text-lg font-bold text-zatara-blue mb-4">خطة الدفع</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <label class="relative flex items-center p-4 bg-white border border-slate-200 rounded-2xl cursor-pointer hover:border-zatara-blue hover:shadow-sm transition-all has-[:checked]:border-zatara-blue has-[:checked]:bg-zatara-blue/5">
                                    <input type="radio" wire:model.live="paymentType" value="full" class="sr-only">
                                    <div class="flex-1 flex justify-between items-center">
                                        <div>
                                            <span class="block font-bold text-zatara-blue">دفع كامل المبلغ</span>
                                            <span class="block text-sm text-slate-500">دفع إجمالي قيمة الحجز الآن</span>
                                        </div>
                                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors border-slate-300" :class="{ 'border-zatara-blue': $wire.paymentType === 'full' }">
                                            <div class="w-2.5 h-2.5 rounded-full bg-zatara-blue opacity-0 transition-opacity" :class="{ 'opacity-100': $wire.paymentType === 'full' }"></div>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="relative flex items-center p-4 bg-white border border-slate-200 rounded-2xl cursor-pointer hover:border-zatara-blue hover:shadow-sm transition-all has-[:checked]:border-zatara-blue has-[:checked]:bg-zatara-blue/5">
                                    <input type="radio" wire:model.live="paymentType" value="deposit" class="sr-only">
                                    <div class="flex-1 flex justify-between items-center">
                                        <div>
                                            <span class="block font-bold text-zatara-blue">دفع عربون ({{ $tripInstance->tripTemplate->deposit_percentage ?? 100 }}%)</span>
                                            <span class="block text-sm text-slate-500">ادفع عربون لتأكيد حجزك وادفع الباقي لاحقاً</span>
                                        </div>
                                        <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors border-slate-300" :class="{ 'border-zatara-blue': $wire.paymentType === 'deposit' }">
                                            <div class="w-2.5 h-2.5 rounded-full bg-zatara-blue opacity-0 transition-opacity" :class="{ 'opacity-100': $wire.paymentType === 'deposit' }"></div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    @endif
                    
                    <h3 class="text-lg font-bold text-zatara-blue mb-4">وسيلة الدفع</h3>
                    <!-- Payment Methods Radio Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        
                        <label class="relative flex flex-col p-6 bg-slate-50 border border-slate-200 rounded-3xl cursor-not-allowed opacity-70">
                            <!-- Premium Coming Soon Badge -->
                            <div class="absolute -top-3 left-6 bg-gradient-to-r from-zatara-gold to-[#b8911f] text-white text-xs font-bold px-4 py-1.5 rounded-full shadow-lg border border-[#e8c86b]">
                                قريباً (Coming Soon)
                            </div>
                            <input type="radio" wire:model.live="paymentMethod" value="stripe" class="sr-only" disabled>
                            <div class="flex justify-between items-center mb-6">
                                <span class="material-symbols-outlined text-[40px] text-slate-400">credit_card</span>
                                <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-colors border-slate-200">
                                </div>
                            </div>
                            <span class="font-bold text-xl text-slate-500">الدفع الإلكتروني</span>
                            <span class="text-sm text-slate-400 mt-2 font-light">بطاقة ائتمانية، مدى، Apple Pay</span>
                        </label>

                        <label class="relative flex flex-col p-6 bg-white border border-slate-100 rounded-3xl cursor-pointer hover:border-zatara-blue hover:shadow-md transition-all has-[:checked]:border-zatara-blue has-[:checked]:bg-zatara-blue/5">
                            <input type="radio" wire:model.live="paymentMethod" value="cash" class="sr-only">
                            <div class="flex justify-between items-center mb-6">
                                <span class="material-symbols-outlined text-[40px] text-zatara-gold">payments</span>
                                <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-colors border-slate-300" :class="{ 'border-zatara-blue': $wire.paymentMethod === 'cash' }">
                                    <div class="w-3 h-3 rounded-full bg-zatara-blue opacity-0 transition-opacity" :class="{ 'opacity-100': $wire.paymentMethod === 'cash' }"></div>
                                </div>
                            </div>
                            <span class="font-bold text-xl text-zatara-blue">الدفع نقداً</span>
                            <span class="text-sm text-slate-500 mt-2 font-light">دفع بالمكتب خلال 24 ساعة من تأكيد الحجز</span>
                        </label>
                        
                        <label class="relative flex flex-col p-6 bg-white border border-slate-100 rounded-3xl cursor-pointer hover:border-zatara-blue hover:shadow-md transition-all has-[:checked]:border-zatara-blue has-[:checked]:bg-zatara-blue/5">
                            <input type="radio" wire:model.live="paymentMethod" value="transfer" class="sr-only">
                            <div class="flex justify-between items-center mb-6">
                                <span class="material-symbols-outlined text-[40px] text-zatara-blue">account_balance</span>
                                <div class="w-6 h-6 rounded-full border-2 flex items-center justify-center transition-colors border-slate-300" :class="{ 'border-zatara-blue': $wire.paymentMethod === 'transfer' }">
                                    <div class="w-3 h-3 rounded-full bg-zatara-blue opacity-0 transition-opacity" :class="{ 'opacity-100': $wire.paymentMethod === 'transfer' }"></div>
                                </div>
                            </div>
                            <span class="font-bold text-xl text-zatara-blue">حوالة بنكية</span>
                            <span class="text-sm text-slate-500 mt-2 font-light">تحويل مباشر لحساب الشركة البنكي</span>
                        </label>

                    </div>

                    <!-- Disclaimer -->
                    <div class="p-5 rounded-2xl bg-slate-50 text-sm text-slate-500 leading-relaxed border border-slate-100 font-medium">
                        <strong class="text-zatara-blue block mb-2 flex items-center gap-1">
                            <span class="material-symbols-outlined text-[18px]">info</span>
                            تنبيه هام
                        </strong>
                        بالنقر على "تأكيد الحجز"، فإنك توافق على الشروط والأحكام وسياسة الإلغاء الخاصة بزتارة. سيتم تأكيد المقاعد فور الدفع بنجاح.
                    </div>

                    <div class="flex justify-between pt-6 border-t border-slate-100 mt-10">
                        <button type="button" wire:click="$set('currentStep', 4)" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-bold py-4 px-8 rounded-2xl transition-all">
                            السابق
                        </button>
                        <button type="submit" class="btn-secondary px-10 py-4 text-lg flex items-center justify-center gap-2 relative disabled:opacity-75" wire:loading.attr="disabled" wire:target="submitBooking">
                            <span wire:loading.remove wire:target="submitBooking" class="flex items-center justify-center gap-2">
                                <span>تأكيد الحجز الآن</span>
                                <span class="material-symbols-outlined">check_circle</span>
                            </span>
                            <span wire:loading wire:target="submitBooking" class="flex items-center justify-center gap-2">
                                <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                جاري تأكيد الحجز...
                            </span>
                        </button>
                    </div>

                    @error('form.passengers')
                        <div class="mt-4 p-4 rounded-xl bg-red-50 text-red-600 text-sm font-bold flex items-center gap-2">
                            <span class="material-symbols-outlined">error</span>
                            {{ $message }}
                        </div>
                    @enderror
                </form>
            </div>

            <!-- Removed Step 6 (Extracted to BookingSuccess Component) -->

        </div>
    </div>
</div>
