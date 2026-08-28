<div class="min-h-screen bg-slate-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8" dir="rtl">
    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10">
        <div class="bg-white border border-slate-100 py-10 px-6 sm:px-12 rounded-[2.5rem] relative overflow-hidden">
            <!-- Decorative plane icon -->
            <div class="absolute -top-4 -left-4 text-zatara-gold/20 rotate-45 transform scale-150 pointer-events-none">
                <span class="material-symbols-outlined" style="font-size: 8rem;">flight</span>
            </div>

            <div class="text-center mb-8 relative z-10">
                <h2 class="text-3xl font-extrabold text-zatara-blue">{{ $tenant->name ?? 'زاتارا للسياحة' }}</h2>
                <p class="text-sm text-slate-500 mt-2 font-medium">سجل دخولك لمتابعة حجوزاتك وإدارة رحلاتك الفاخرة</p>
            </div>
            
            <form class="space-y-6" wire:submit.prevent="{{ $step === 1 ? 'sendOtp' : 'verifyOtp' }}">
                @if ($step === 1)
                    <div>
                        <label for="phone" class="block text-sm font-bold text-zatara-blue mb-2">
                            رقم الهاتف
                        </label>
                        <div class="relative mt-1 rounded-md shadow-sm">
                            <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                <span class="material-symbols-outlined text-zatara-blue/40">phone_iphone</span>
                            </div>
                            <input wire:model="identifier" id="identifier" type="tel" autocomplete="tel" required
                                   class="glass-input block w-full pr-12 pl-4 py-4 sm:text-lg border-gray-300 rounded-2xl focus:ring-zatara-gold focus:border-zatara-gold transition-all duration-300"
                                   placeholder="05xxxxxxxx" dir="ltr">
                        </div>
                        @error('identifier') <span class="text-red-500 text-sm mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" class="w-full flex justify-center btn-primary text-lg py-4 rounded-2xl" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="sendOtp">متابعة</span>
                        <span wire:loading wire:target="sendOtp" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            جاري الإرسال...
                        </span>
                    </button>

                    <!-- Social Login Divider -->
                    <div class="mt-8">
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-slate-200"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-4 bg-white/50 text-slate-500 font-medium">أو سجل دخولك عبر</span>
                            </div>
                        </div>

                        <div class="mt-6 relative">
                            {{-- Not wired to a real OAuth flow yet (no Socialite integration, no
                                 backend route) -- was previously a dead button with no wire:click/
                                 href that silently did nothing on click. Disabled with the same
                                 "قريباً" convention already used for the electronic payment method
                                 at checkout Step 4, rather than left clickable-but-broken. --}}
                            <div class="absolute -top-3 left-6 bg-gradient-to-r from-zatara-gold to-[#b8911f] text-white text-xs font-bold px-4 py-1.5 rounded-full border border-[#e8c86b] z-10">
                                قريباً
                            </div>
                            <button type="button" disabled class="w-full flex justify-center items-center gap-3 bg-slate-50 border border-slate-200 rounded-2xl py-3 px-6 cursor-not-allowed opacity-70">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                                </svg>
                                <span class="text-slate-500 font-bold">الدخول عبر Google</span>
                            </button>
                        </div>
                    </div>
                @else
                    <div>
                        <label for="otp" class="block text-sm font-bold text-zatara-blue mb-2 text-center">
                            أدخل رمز التحقق المرسل لهاتفك
                        </label>
                        <input wire:model="otp" id="otp" type="text" required class="glass-input block w-full text-center py-4 text-3xl tracking-[1em] border-gray-300 rounded-2xl focus:ring-zatara-gold focus:border-zatara-gold transition-all" placeholder="----">
                        @error('otp') <span class="text-red-500 text-sm mt-1 block text-center">{{ $message }}</span> @enderror
                    </div>

                    <button type="submit" class="w-full flex justify-center btn-primary text-lg py-4 rounded-2xl mt-6" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="verifyOtp">تحقق ودخول</span>
                        <span wire:loading wire:target="verifyOtp" class="flex items-center">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            جاري التحقق...
                        </span>
                    </button>
                    
                    <div class="mt-6 text-center">
                        <p class="text-sm text-slate-500 font-medium">إعادة الإرسال بعد <span class="text-zatara-blue font-bold">00:60</span></p>
                        <button type="button" wire:click="$set('step', 1)" class="mt-2 text-sm text-zatara-gold hover:text-zatara-blue font-bold transition-colors">
                            تغيير رقم الهاتف
                        </button>
                    </div>
                @endif
            </form>
        </div>
</div>
