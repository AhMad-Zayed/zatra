<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة العملاء - تسجيل الدخول</title>
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
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col justify-between">

    <!-- Top bar -->
    <header class="bg-white border-b border-gray-100 py-4 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 flex justify-between items-center">
            <span class="text-xl font-extrabold text-primary-600">زاتارا للسياحة</span>
            <a href="{{ route('storefront.home', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" 
               class="text-sm font-semibold text-gray-500 hover:text-primary-600 transition-colors">
                ← العودة للرئيسية
            </a>
        </div>
    </header>

    <!-- Main Container -->
    <main class="flex-grow flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl p-8 border border-gray-100 shadow-xl max-w-md w-full space-y-6">
            <div class="text-center">
                <div class="w-16 h-16 bg-primary-50 rounded-2xl flex items-center justify-center mx-auto mb-4 text-primary-600">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-black text-gray-900">تسجيل دخول الزبائن</h1>
                <p class="text-xs text-gray-400 mt-1">تنزيل تذاكر الطيران، قسائم الفنادق ووثائق التأمين</p>
            </div>

            <!-- Alerts -->
            <div id="alert-box" class="hidden px-4 py-3 rounded-2xl text-xs border"></div>

            <!-- Login Form -->
            <form id="login-form" class="space-y-4">
                @csrf
                <!-- Phone input -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">رقم الهاتف الجوال</label>
                    <input type="tel" id="phone" name="phone" placeholder="+970 59 XXX XXXX" dir="ltr"
                           class="w-full h-12 px-4 rounded-xl border border-gray-200 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 outline-none text-sm transition-all text-left">
                </div>

                <!-- OTP input (Hidden initially) -->
                <div id="otp-group" class="hidden">
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">أدخل رمز التحقق (OTP)</label>
                    <input type="text" id="otp" name="otp" placeholder="XXXX" dir="ltr"
                           class="w-full h-12 px-4 text-center rounded-xl border border-gray-200 focus:border-primary-500 focus:ring-2 focus:ring-primary-100 outline-none text-lg font-bold tracking-widest transition-all">
                    
                    <div class="bg-amber-50 border border-amber-100 text-amber-800 rounded-xl p-3 text-xs leading-relaxed mt-3">
                        <strong>لأغراض تجريبية:</strong> رمز التحقق هو <strong>1234</strong> (أو يمكنك مراجعة سجلات النظام لمعرفة الرمز العشوائي).
                    </div>
                </div>

                <!-- Buttons -->
                <button type="button" id="submit-btn" 
                        class="w-full bg-primary-600 hover:bg-primary-700 text-white rounded-xl font-bold h-12 transition-colors flex items-center justify-center gap-2">
                    <span>إرسال رمز التحقق (OTP)</span>
                </button>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 text-gray-400 py-6 text-center text-xs border-t border-gray-900">
        <p>© {{ date('Y') }} زاتارا للسياحة - بوابة وثائق السفر.</p>
    </footer>

    <!-- Fetch & DOM Logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const phoneInput = document.getElementById('phone');
            const otpInput = document.getElementById('otp');
            const otpGroup = document.getElementById('otp-group');
            const submitBtn = document.getElementById('submit-btn');
            const alertBox = document.getElementById('alert-box');
            const form = document.getElementById('login-form');

            let isOtpSent = false;

            function showAlert(message, type = 'error') {
                alertBox.textContent = message;
                alertBox.classList.remove('hidden', 'bg-red-50', 'border-red-100', 'text-red-700', 'bg-primary-50', 'border-primary-100', 'text-primary-800');
                
                if (type === 'error') {
                    alertBox.classList.add('bg-red-50', 'border-red-100', 'text-red-700');
                } else {
                    alertBox.classList.add('bg-primary-50', 'border-primary-100', 'text-primary-800');
                }
            }

            submitBtn.addEventListener('click', async function () {
                const phone = phoneInput.value.trim();
                if (!phone) {
                    showAlert('يرجى إدخال رقم الهاتف.');
                    return;
                }

                if (!isOtpSent) {
                    // Send OTP
                    submitBtn.disabled = true;
                    submitBtn.querySelector('span').textContent = 'جاري الإرسال...';
                    
                    try {
                        const response = await fetch("{{ route('portal.send_otp', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ phone })
                        });

                        const result = await response.json();

                        if (response.ok) {
                            isOtpSent = true;
                            otpGroup.classList.remove('hidden');
                            phoneInput.disabled = true;
                            showAlert(result.message, 'success');
                            submitBtn.querySelector('span').textContent = 'تسجيل الدخول';
                        } else {
                            showAlert(result.message || 'فشل إرسال الرمز.');
                            submitBtn.querySelector('span').textContent = 'إرسال رمز التحقق (OTP)';
                        }
                    } catch (err) {
                        showAlert('حدث خطأ في الاتصال بالخادم.');
                        submitBtn.querySelector('span').textContent = 'إرسال رمز التحقق (OTP)';
                    } finally {
                        submitBtn.disabled = false;
                    }
                } else {
                    // Verify OTP
                    const otp = otpInput.value.trim();
                    if (!otp) {
                        showAlert('يرجى إدخال رمز التحقق.');
                        return;
                    }

                    submitBtn.disabled = true;
                    submitBtn.querySelector('span').textContent = 'جاري التحقق...';

                    try {
                        const response = await fetch("{{ route('portal.verify_otp', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ phone, otp })
                        });

                        const result = await response.json();

                        if (response.ok) {
                            showAlert('تم التحقق بنجاح! جاري تحويلك...', 'success');
                            window.location.href = result.redirect_url;
                        } else {
                            showAlert(result.message || 'رمز التحقق غير صحيح.');
                        }
                    } catch (err) {
                        showAlert('حدث خطأ في الاتصال بالخادم.');
                    } finally {
                        submitBtn.disabled = false;
                    }
                }
            });
        });
    </script>

</body>
</html>
