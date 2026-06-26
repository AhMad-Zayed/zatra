<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Booking;
use App\Services\CustomerAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PortalController extends Controller
{
    protected CustomerAuthService $authService;

    public function __construct(CustomerAuthService $authService)
    {
        $this->authService = $authService;
    }

    public function showLogin(Request $request)
    {
        $tenant = app(Tenant::class);
        return view('portal.login', compact('tenant'));
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:7',
        ]);

        if (!app()->environment('testing')) {
            $key = 'send-otp:' . $request->phone;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                return response()->json([
                    'success' => false,
                    'message' => "لقد تجاوزت الحد الأقصى للمحاولات. يرجى الانتظار {$seconds} ثانية.",
                ], 429);
            }
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        try {
            $this->authService->sendOtp($request->phone);
            return response()->json([
                'success' => true,
                'message' => 'تم إرسال رمز التحقق بنجاح إلى هاتفك.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إرسال الرمز: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|min:7',
            'otp' => 'required|string|min:4',
        ]);

        if (!app()->environment('testing')) {
            $key = 'verify-otp:' . $request->phone;
            if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
                return response()->json([
                    'success' => false,
                    'message' => "لقد تجاوزت الحد الأقصى للمحاولات. يرجى الانتظار {$seconds} ثانية.",
                ], 429);
            }
        }

        if ($this->authService->verifyOtp($request->phone, $request->otp)) {
            if (!app()->environment('testing')) {
                \Illuminate\Support\Facades\RateLimiter::clear($key);
            }
            $tenant = app(Tenant::class);
            
            // Find or create customer
            $customer = $this->authService->findOrCreateByPhone($request->phone, null, $tenant->id);
            
            // Log in the user
            Auth::login($customer);

            return response()->json([
                'success' => true,
                'redirect_url' => route('portal.dashboard', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]),
            ]);
        }

        if (!app()->environment('testing')) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        return response()->json([
            'success' => false,
            'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
        ], 422);
    }

    public function dashboard(Request $request)
    {
        $tenant = app(Tenant::class);
        $user = Auth::user();

        // Get bookings belonging to this customer and scoped to the active tenant
        $bookings = Booking::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->with(['tripInstance.tripTemplate', 'passengers'])
            ->latest()
            ->get();

        return view('portal.dashboard', compact('tenant', 'bookings'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $tenant = app(Tenant::class);
        return redirect()->route('portal.login', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]);
    }
}
