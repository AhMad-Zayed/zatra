<div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8" dir="rtl">
    <div class="max-w-md w-full space-y-8 bg-white p-10 rounded-2xl shadow-xl">
        <div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                مرحباً بك في {{ $tenant->name }}
            </h2>
            <p class="mt-2 text-center text-sm text-gray-600">
                سجل الدخول لإدارة حجوزاتك
            </p>
        </div>
        
        <form class="mt-8 space-y-6" wire:submit.prevent="{{ $step === 1 ? 'sendOtp' : 'verifyOtp' }}">
            @if ($step === 1)
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="identifier" class="sr-only">رقم الجوال</label>
                        <input wire:model="identifier" id="identifier" name="identifier" type="text" required class="appearance-none rounded-md relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm" placeholder="رقم الجوال (مثال: 0500000000)">
                    </div>
                </div>
                @error('identifier') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors shadow-lg">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <svg wire:loading wire:target="sendOtp" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                        أرسل رمز التحقق
                    </button>
                </div>
            @else
                <div class="rounded-md shadow-sm -space-y-px">
                    <div>
                        <label for="otp" class="sr-only">رمز التحقق</label>
                        <input wire:model="otp" id="otp" name="otp" type="text" required class="appearance-none rounded-md relative block w-full px-3 py-3 border border-gray-300 placeholder-gray-500 text-gray-900 focus:outline-none focus:ring-primary-500 focus:border-primary-500 focus:z-10 sm:text-sm text-center tracking-[0.5em] text-2xl font-bold" placeholder="----">
                    </div>
                </div>
                @error('otp') <span class="text-red-500 text-sm">{{ $message }}</span> @enderror

                <div>
                    <button type="submit" class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors shadow-lg">
                        <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                            <svg wire:loading wire:target="verifyOtp" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                        تأكيد الدخول
                    </button>
                </div>
                
                <div class="text-center mt-4">
                    <button type="button" wire:click="$set('step', 1)" class="text-sm text-blue-600 hover:text-blue-500">
                        تغيير رقم الجوال
                    </button>
                </div>
            @endif
        </form>
    </div>
</div>
