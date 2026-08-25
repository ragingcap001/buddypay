<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Authentication\Services\DeviceService;
use App\Domain\Authentication\Services\OtpService;
use App\Domain\Authentication\Services\PinService;
use App\Domain\KYC\Enums\KycStatus;
use App\Domain\KYC\Enums\KycTier;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WalletResource;
use App\Http\Support\ApiResponse;
use App\Models\KycProfile;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly PinService $pins,
        private readonly DeviceService $devices,
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * POST /api/v1/auth/register
     *
     * Creates the user (with wallet + KYC profile) and issues a
     * registration OTP. The account can only sign in after OTP
     * verification.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $phone = $this->normalizePhone((string) $request->input('phone'));

        if (User::where('phone', $phone)->exists()) {
            return ApiResponse::error('USER_EXISTS', 'An account with this phone number already exists.', 409, $request);
        }

        $result = DB::transaction(function () use ($request, $phone): User {
            $user = User::create([
                'name' => $request->input('name'),
                'phone' => $phone,
                'email' => $request->input('email'),
                'password' => $request->input('password'),
                'status' => UserStatus::Active->value,
            ]);

            $this->wallets->createUserWallet($user->id);

            KycProfile::create([
                'user_id' => $user->id,
                'status' => KycStatus::Pending->value,
                'tier' => KycTier::Unverified->value,
            ]);

            return $user;
        });

        $otpResult = $this->otp->issue($result, OtpChallenge::PURPOSE_REGISTER);
        $this->audit->log('auth.register', $result, $result);

        $payload = [
            'user' => UserResource::make($result),
            'message' => 'Registration started. Verify your phone number with the one-time code.',
        ];

        if (app()->environment('local', 'testing')) {
            // Development convenience only — never expose codes in production.
            $payload['dev_otp'] = $otpResult['code'];
        }

        return ApiResponse::success($payload, 'Registration started', 201);
    }

    /**
     * POST /api/v1/auth/verify-otp
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $phone = $this->normalizePhone((string) $request->input('phone'));

        $user = User::where('phone', $phone)->first();

        if ($user === null) {
            return ApiResponse::error('USER_NOT_FOUND', 'No account found for this phone number.', 404, $request);
        }

        $challenge = $this->otp->latestChallenge($user, OtpChallenge::PURPOSE_REGISTER);

        if ($challenge === null) {
            return ApiResponse::error('NO_ACTIVE_CHALLENGE', 'No pending verification for this phone number. Register first.', 404, $request);
        }

        // Throws FinancialException (OTP_INVALID / OTP_EXPIRED / OTP_LOCKED) on failure.
        $this->otp->verify($challenge, (string) $request->input('otp'));

        $user->update(['phone_verified_at' => now()]);
        $this->devices->recordLogin($user, $request);
        $this->audit->log('auth.phone_verified', $user, $user);

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user' => UserResource::make($user->refresh()),
            'wallet' => new WalletResource($user->wallet),
            'token' => $token,
        ], 'Phone verified. You are now signed in.');
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $phone = $this->normalizePhone((string) $request->input('phone'));

        $user = User::where('phone', $phone)->first();

        // Do not reveal whether the phone or the password was wrong.
        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Invalid phone number or password.', 401, $request);
        }

        if (! $user->isActive()) {
            return ApiResponse::error('USER_SUSPENDED', 'This account is suspended. Contact support.', 403, $request);
        }

        if (! $user->isPhoneVerified()) {
            return ApiResponse::error('UNVERIFIED_USER', 'Your phone number has not been verified yet.', 403, $request);
        }

        $this->devices->recordLogin($user, $request);
        $this->audit->log('auth.login', $user, $user);

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::success([
            'user' => UserResource::make($user),
            'wallet' => new WalletResource($user->wallet),
            'token' => $token,
        ], 'Signed in successfully.');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $user?->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Signed out.');
    }

    /**
     * POST /api/v1/auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $phone = $this->normalizePhone((string) $request->input('phone'));

        $user = User::where('phone', $phone)->first();

        // Always the same response to avoid account enumeration.
        if ($user !== null) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->insert([
                'phone' => $phone,
                'token' => $token,
                'expires_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->log('auth.password_reset_requested', $user, $user);

            $payload = ['message' => 'If this phone number is registered, a reset link has been sent.'];

            if (app()->environment('local', 'testing')) {
                $payload['dev_reset_token'] = $token;
            }

            return ApiResponse::success($payload);
        }

        return ApiResponse::success(['message' => 'If this phone number is registered, a reset link has been sent.']);
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $row = DB::table('password_reset_tokens')
            ->where('token', $request->input('token'))
            ->where('expires_at', '>', now())
            ->first();

        if ($row === null) {
            return ApiResponse::error('RESET_TOKEN_INVALID', 'This reset token is invalid or has expired.', 422, $request);
        }

        $user = User::where('phone', $row->phone)->first();

        if ($user !== null) {
            $user->update(['password' => $request->input('password')]);
            $this->audit->log('auth.password_reset', $user, $user);
        }

        DB::table('password_reset_tokens')->where('token', $request->input('token'))->delete();

        return ApiResponse::success(null, 'Password updated.');
    }

    /**
     * POST /api/v1/auth/pin — set/change the transaction PIN.
     */
    public function setPin(SetPinRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            return ApiResponse::error('INVALID_CREDENTIALS', 'Password is incorrect.', 401, $request);
        }

        $this->pins->setPin($user, (string) $request->input('pin'));

        return ApiResponse::success(null, 'Transaction PIN set.');
    }

    /**
     * POST /api/v1/auth/verify-pin
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $valid = $this->pins->hasPin($user) && $this->pins->verify($user, (string) $request->input('pin'));

        return ApiResponse::success(['valid' => $valid]);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);

        if (str_starts_with($phone, '+234')) {
            return '0'.substr($phone, 4);
        }

        return $phone;
    }
}
