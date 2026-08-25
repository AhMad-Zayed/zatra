<div class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/30 to-zatara-gold/5 flex flex-col justify-center py-12 sm:px-6 lg:px-8" dir="rtl">
    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md relative z-10">
        <div class="glass-panel bg-white/70 backdrop-blur-xl border border-white/50 py-10 px-6 sm:px-12 rounded-[2.5rem] shadow-2xl relative overflow-hidden">
            <div class="text-center mb-8 relative z-10">
                <h2 class="text-3xl font-extrabold text-zatara-blue">{{ $tenant->name ?? 'زاتارا للسياحة' }}</h2>
                <p class="text-sm text-slate-500 mt-2 font-medium">
                    يوجد حساب مسجل بهذا البريد الإلكتروني بالفعل. لتأكيد أنه حسابك، أدخل رمز
                    التحقق المرسل إلى <span class="font-bold text-zatara-blue" dir="ltr">{{ $maskedEmail }}</span>
                </p>
            </div>

            <form class="space-y-6" wire:submit.prevent="verifyOtp">
                <div>
                    <label for="otp" class="block text-sm font-bold text-zatara-blue mb-2 text-center">
                        أدخل رمز التحقق
                    </label>
                    <input wire:model="otp" id="otp" type="text" required
                           class="glass-input block w-full text-center py-4 text-3xl tracking-[1em] border-gray-300 rounded-2xl focus:ring-zatara-gold focus:border-zatara-gold transition-all"
                           placeholder="----">
                    @error('otp') <span class="text-red-500 text-sm mt-1 block text-center">{{ $message }}</span> @enderror
                </div>

                <button type="submit" class="w-full flex justify-center btn-primary text-lg py-4 rounded-2xl bg-zatara-blue text-white hover:bg-blue-900 transition-colors shadow-lg" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="verifyOtp">تحقق وربط الحساب</span>
                    <span wire:loading wire:target="verifyOtp" class="flex items-center">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        جاري التحقق...
                    </span>
                </button>

                <div class="mt-6 text-center">
                    <button type="button" wire:click="cancel" class="text-sm text-slate-500 hover:text-zatara-blue font-bold transition-colors">
                        إلغاء وتسجيل الدخول بطريقة أخرى
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
