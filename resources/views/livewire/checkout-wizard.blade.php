<div class="max-w-4xl mx-auto bg-slate-950 border border-slate-800 rounded-2xl shadow-xl overflow-hidden" x-data="{ step: @entangle('currentStep') }">
    
    <!-- Header / Progress Bar -->
    <div class="bg-slate-900 px-6 py-4 border-b border-slate-800">
        <h2 class="text-xl font-bold text-white mb-4">إتمام الحجز - {{ $tripInstance->tripTemplate->name ?? 'رحلة' }}</h2>
        
        <div class="flex items-center justify-between relative">
            <div class="absolute left-0 top-1/2 -translate-y-1/2 w-full h-1 bg-slate-800 rounded z-0"></div>
            <div class="absolute right-0 top-1/2 -translate-y-1/2 h-1 bg-amber-500 rounded z-0 transition-all duration-300" :style="'width: ' + ((step - 1) / 3 * 100) + '%'"></div>
            
            <template x-for="i in 4" :key="i">
                <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full border-2 transition-colors duration-300"
                     :class="step >= i ? 'bg-amber-500 border-amber-500 text-slate-900' : 'bg-slate-900 border-slate-700 text-slate-500'">
                    <span class="text-sm font-bold" x-text="i"></span>
                </div>
            </template>
        </div>
    </div>

    <!-- Wizard Body -->
    <div class="p-6 sm:p-10 relative min-h-[400px]">
        
        <!-- STEP 1: Phone Number -->
        <div x-show="step === 1" x-transition.opacity.duration.300ms class="space-y-6">
            <div class="text-center">
                <h3 class="text-2xl font-bold text-white mb-2">مرحباً بك!</h3>
                <p class="text-slate-400">أدخل رقم هاتفك للبدء بإجراءات الحجز.</p>
            </div>

            <form wire:submit.prevent="submitPhone" class="max-w-md mx-auto space-y-4 mt-8">
                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-300 mb-2">رقم الهاتف</label>
                    <input type="text" id="phone" wire:model="form.phone" dir="ltr"
                           class="block w-full rounded-lg border-slate-700 bg-slate-900 text-white shadow-sm focus:border-amber-500 focus:ring-amber-500 text-left px-4 py-3" 
                           placeholder="+966500000000">
                    @error('form.phone') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                </div>
                
                <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition-colors mt-6">
                    <span wire:loading.remove wire:target="submitPhone">متابعة</span>
                    <span wire:loading wire:target="submitPhone">جاري التحقق...</span>
                </button>
            </form>
        </div>

        <!-- STEP 2: OTP Verification -->
        <div x-show="step === 2" x-transition.opacity.duration.300ms x-cloak class="space-y-6">
            <div class="text-center">
                <h3 class="text-2xl font-bold text-white mb-2">رمز التحقق</h3>
                <p class="text-slate-400">لقد أرسلنا رمزاً مكوناً من 6 أرقام إلى <span class="text-amber-500" x-text="$wire.form.phone"></span></p>
            </div>

            <form wire:submit.prevent="verifyOtp" class="max-w-md mx-auto space-y-4 mt-8">
                <div>
                    <label for="otp" class="block text-sm font-medium text-slate-300 mb-2">أدخل الرمز</label>
                    <input type="text" id="otp" wire:model="form.otp" dir="ltr" maxlength="6"
                           class="block w-full text-center tracking-widest text-2xl rounded-lg border-slate-700 bg-slate-900 text-white shadow-sm focus:border-amber-500 focus:ring-amber-500 px-4 py-3" 
                           placeholder="------">
                    @error('form.otp') <span class="text-red-500 text-sm mt-1 block text-center">{{ $message }}</span> @enderror
                </div>
                
                <div class="flex gap-4 mt-6">
                    <button type="button" wire:click="$set('currentStep', 1)" class="w-1/3 py-3 px-4 border border-slate-700 rounded-lg shadow-sm text-sm font-medium text-slate-300 bg-transparent hover:bg-slate-800 transition-colors">
                        رجوع
                    </button>
                    <button type="submit" class="w-2/3 flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 transition-colors">
                        <span wire:loading.remove wire:target="verifyOtp">تحقق</span>
                        <span wire:loading wire:target="verifyOtp">جاري التحقق...</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- STEP 3: Passengers -->
        <div x-show="step === 3" x-transition.opacity.duration.300ms x-cloak class="space-y-6">
            <h3 class="text-2xl font-bold text-white border-b border-slate-800 pb-4">بيانات الركاب</h3>
            
            @error('form.passengers') 
                <div class="bg-red-500/10 border border-red-500 text-red-500 p-4 rounded-lg">
                    {{ $message }}
                </div>
            @enderror

            <div class="space-y-4">
                @foreach($form->passengers as $index => $passenger)
                    <div class="bg-slate-900 border border-slate-700 p-4 rounded-xl flex flex-col md:flex-row gap-4 items-start md:items-center">
                        
                        <div class="flex-grow w-full">
                            <label class="block text-sm font-medium text-slate-300 mb-1">فئة التذكرة (الراكب {{ $index + 1 }})</label>
                            <select wire:model="form.passengers.{{ $index }}.trip_pricing_tier_id"
                                    class="block w-full rounded-lg border-slate-700 bg-slate-950 text-white focus:border-amber-500 focus:ring-amber-500">
                                <option value="">-- اختر الفئة --</option>
                                @foreach($tripInstance->tripPricingTiers as $tier)
                                    <option value="{{ $tier->id }}">{{ $tier->name }} - {{ number_format($tier->price, 2) }} ريال</option>
                                @endforeach
                            </select>
                            @error('form.passengers.'.$index.'.trip_pricing_tier_id') 
                                <span class="text-red-500 text-xs mt-1">{{ $message }}</span> 
                            @enderror
                        </div>

                        <!-- Add dynamic fields here if needed (e.g. name, passport) based on tenant config -->
                        
                        @if(count($form->passengers) > 1)
                            <button type="button" wire:click="form.removePassenger({{ $index }})" class="mt-6 md:mt-0 text-red-500 hover:text-red-400 p-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="form.addPassenger" class="inline-flex items-center text-sm font-medium text-amber-500 hover:text-amber-400 mt-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                إضافة راكب آخر
            </button>

            <div class="flex gap-4 mt-8 pt-6 border-t border-slate-800">
                <button type="button" wire:click="submitPassengers" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 transition-colors">
                    الاستمرار
                </button>
            </div>
        </div>

        <!-- STEP 4: Addons & Checkout -->
        <div x-show="step === 4" x-transition.opacity.duration.300ms x-cloak class="space-y-6">
            <h3 class="text-2xl font-bold text-white border-b border-slate-800 pb-4">الإضافات والمراجعة النهائية</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Addons Selection -->
                <div>
                    <h4 class="text-lg font-medium text-slate-300 mb-4">هل ترغب بإضافة خدمات أخرى؟</h4>
                    
                    @if($tripInstance->tripAddons && $tripInstance->tripAddons->count() > 0)
                        <div class="space-y-3">
                            @foreach($tripInstance->tripAddons as $addon)
                                @php
                                    $isSelected = collect($form->addons)->contains('trip_addon_id', $addon->id);
                                @endphp
                                <div class="bg-slate-900 border {{ $isSelected ? 'border-amber-500' : 'border-slate-700' }} p-4 rounded-xl cursor-pointer transition-colors"
                                     wire:click="form.toggleAddon({{ $addon->id }})">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <h5 class="font-bold text-white">{{ $addon->name }}</h5>
                                            <p class="text-xs text-slate-400 mt-1">+{{ number_format($addon->price, 2) }} ريال</p>
                                        </div>
                                        <div class="h-5 w-5 rounded-full border-2 flex items-center justify-center {{ $isSelected ? 'border-amber-500 bg-amber-500' : 'border-slate-600' }}">
                                            @if($isSelected)
                                                <svg class="h-3 w-3 text-slate-900" fill="currentColor" viewBox="0 0 20 20"><path d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-slate-500 italic">لا توجد إضافات متاحة لهذه الرحلة.</p>
                    @endif
                </div>

                <!-- Order Summary -->
                <div class="bg-slate-900 border border-slate-700 p-6 rounded-xl h-fit">
                    <h4 class="text-lg font-bold text-white mb-4">ملخص الطلب</h4>
                    
                    <ul class="space-y-2 text-sm text-slate-300 mb-6 border-b border-slate-800 pb-4">
                        <li class="flex justify-between">
                            <span>عدد الركاب</span>
                            <span class="font-bold text-white">{{ count($form->passengers) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>رقم الهاتف المرتبط</span>
                            <span class="font-bold text-white" dir="ltr">{{ $form->phone }}</span>
                        </li>
                    </ul>

                    <div class="bg-amber-500/10 border border-amber-500/20 p-4 rounded-lg">
                        <p class="text-xs text-amber-500 font-bold text-center mb-1">تنبيه أمني</p>
                        <p class="text-xs text-slate-400 text-center">
                            سيتم حساب التكلفة النهائية بدقة وتوثيقها في قاعدة البيانات فور تأكيد الحجز لضمان أقصى درجات الأمان.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 mt-8 pt-6 border-t border-slate-800">
                <button type="button" wire:click="$set('currentStep', 3)" class="w-1/3 py-3 px-4 border border-slate-700 rounded-lg shadow-sm text-sm font-medium text-slate-300 bg-transparent hover:bg-slate-800 transition-colors">
                    رجوع للركاب
                </button>
                <button type="button" wire:click="submitBooking" class="w-2/3 flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-bold text-slate-900 bg-amber-500 hover:bg-amber-400 transition-colors relative overflow-hidden group">
                    <span wire:loading.remove wire:target="submitBooking">تأكيد الحجز النهائي</span>
                    <span wire:loading wire:target="submitBooking">جاري معالجة الحجز...</span>
                    
                    <!-- Micro-animation for active state -->
                    <div class="absolute inset-0 bg-white/20 translate-y-full group-hover:translate-y-0 transition-transform duration-300 ease-in-out pointer-events-none"></div>
                </button>
            </div>
        </div>
        
    </div>
</div>
