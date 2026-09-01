<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Authentication\Services\DeviceService;
use App\Domain\Authentication\Services\OtpService;
use App\Domain\KYC\Enums\KycStatus;
use App\Domain\KYC\Enums\KycTier;
use App\Domain\Users\Enums\UserStatus;
use App\Domain\Wallet\Services\WalletService;
use App\Exceptions\FinancialException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendEmailOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Requests\Auth\VerifyResetOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\KycProfile;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Session lifecycle: register -> verify-email -> login / logout, plus the
 * OTP-based password reset. Self-service actions on an already-authenticated
 * account (profile, PIN, device token, notifications) live in UserController.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly DeviceService $devices,
        private readonly WalletService $wallets,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * POST /v1/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $phone = $this->normalizePhone((string) $request->input('phone'));

        $user = DB::transaction(function () use ($request, $phone): User {
            $user = User::create([
                'first_name' => $request->input('firstName'),
                'last_name' => $request->input('lastName'),
                'email' => $request->input('email'),
                'phone' => $phone,
                'gender' => $request->input('gender'),
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

        $otpResult = $this->otp->issue($user, OtpChallenge::PURPOSE_EMAIL_VERIFY);
        $this->audit->log('auth.register', $user, $user);

        $payload = ['message' => 'Registration successful. OTP sent to email.'];

        if (app()->environment('local', 'testing')) {
            // Development convenience only — never expose codes in production.
            $payload['dev_otp'] = $otpResult['code'];
        }

        return response()->json($payload, 201);
    }

    /**
     * POST /v1/verify-email
     */
    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if ($user === null) {
            return response()->json(['message' => 'No account found for this email address.'], 404);
        }

        $challenge = $this->otp->latestChallenge($user, OtpChallenge::PURPOSE_EMAIL_VERIFY);

        if ($challenge === null) {
            return response()->json(['message' => 'No pending verification for this email address. Register first.'], 404);
        }

        // Throws FinancialException (OTP_INVALID / OTP_EXPIRED / OTP_LOCKED) on failure.
        $this->otp->verify($challenge, (string) $request->input('otp'));

        // Not a mass-assignment update() — email_verified_at is deliberately
        // absent from $fillable (a client must never be able to set it via
        // request input), so this has to bypass that guard directly.
        $user->email_verified_at = now();
        $user->save();
        $this->devices->recordLogin($user, $request);
        $this->audit->log('auth.email_verified', $user, $user);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => UserResource::make($user->refresh()),
            'token' => $token,
        ]);
    }

    /**
     * POST /v1/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        // Do not reveal whether the email or the password was wrong.
        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'This account is suspended. Contact support.'], 403);
        }

        if ($user->email_verified_at === null) {
            return response()->json(['message' => 'Your email address has not been verified yet.'], 403);
        }

        $this->devices->recordLogin($user, $request);
        $this->audit->log('auth.login', $user, $user);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }

    /**
     * POST /v1/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $user?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * POST /v1/resend-email-otp
     */
    public function resendEmailOtp(ResendEmailOtpRequest $request): JsonResponse
    {
        // ResendEmailOtpRequest already validates exists:users,email, so
        // this should never miss in practice — first() rather than
        // firstOrFail() anyway, so a race (however unlikely) renders this
        // endpoint's own {message} shape instead of Laravel's default
        // off-envelope 404.
        $user = User::where('email', $request->input('email'))->first();

        if ($user === null) {
            return response()->json(['message' => 'The selected email is invalid.'], 422);
        }

        if ($user->email_verified_at !== null) {
            return response()->json(['message' => 'Email already verified.'], 400);
        }

        $otpResult = $this->otp->issue($user, OtpChallenge::PURPOSE_EMAIL_VERIFY);

        $payload = ['message' => 'A new OTP has been sent to your email.'];

        if (app()->environment('local', 'testing')) {
            $payload['dev_otp'] = $otpResult['code'];
        }

        return response()->json($payload);
    }

    /**
     * POST /v1/forgot-password
     *
     * Always the same response regardless of whether the email is
     * registered, to avoid account enumeration.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        $payload = ['status' => true, 'message' => 'OTP sent to your email for password reset.'];

        if ($user !== null) {
            $otpResult = $this->otp->issue($user, OtpChallenge::PURPOSE_PASSWORD_RESET);
            $this->audit->log('auth.password_reset_requested', $user, $user);

            if (app()->environment('local', 'testing')) {
                $payload['dev_otp'] = $otpResult['code'];
            }
        }

        return response()->json($payload);
    }

    /**
     * POST /v1/verify-reset-otp
     *
     * Only unlocks the "set a new password" screen — the code is checked
     * again, and consumed, by reset-password itself.
     */
    public function verifyResetOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();
        $challenge = $user !== null ? $this->otp->latestChallenge($user, OtpChallenge::PURPOSE_PASSWORD_RESET) : null;

        if ($user === null || $challenge === null || ! $this->otp->peek($challenge, (string) $request->input('otp'))) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired OTP.']);
        }

        return response()->json(['status' => true, 'message' => 'OTP is valid. You can now reset your password.']);
    }

    /**
     * POST /v1/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();
        $challenge = $user !== null ? $this->otp->latestChallenge($user, OtpChallenge::PURPOSE_PASSWORD_RESET) : null;

        if ($user === null || $challenge === null) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired OTP.'], 422);
        }

        try {
            $this->otp->verify($challenge, (string) $request->input('otp'));
        } catch (FinancialException) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired OTP.'], 422);
        }

        $user->update(['password' => $request->input('password')]);
        $this->audit->log('auth.password_reset', $user, $user);

        return response()->json(['status' => true, 'message' => 'Password reset successful.']);
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
