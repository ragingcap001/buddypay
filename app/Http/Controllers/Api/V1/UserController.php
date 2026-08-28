<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authentication\Services\PinService;
use App\Domain\Wallet\ValueObjects\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPinRequest;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Requests\Auth\VerifyPinRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\UpdateDeviceTokenRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service actions on the authenticated account: profile, password,
 * transaction PIN, device token. Session lifecycle (register/login/
 * logout/password-reset) lives in AuthController.
 */
class UserController extends Controller
{
    public function __construct(private readonly PinService $pins)
    {
    }

    /**
     * GET /v1/user/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json(['data' => $this->profilePayload($user)]);
    }

    /**
     * PUT /v1/user/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->authUser($request);

        $updates = array_filter([
            'first_name' => $request->input('firstName'),
            'last_name' => $request->input('lastName'),
            'gender' => $request->input('gender'),
            'phone' => $request->has('phone') ? $this->normalizePhone((string) $request->input('phone')) : null,
        ], fn (mixed $value): bool => $value !== null);

        $user->update($updates);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $this->profilePayload($user->refresh()),
        ]);
    }

    /**
     * PUT /v1/user/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $this->authUser($request);

        if (! Hash::check((string) $request->input('currentPassword'), $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 401);
        }

        $user->update(['password' => $request->input('newPassword')]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * POST /v1/user/update-device-token
     */
    public function updateDeviceToken(UpdateDeviceTokenRequest $request): JsonResponse
    {
        $user = $this->authUser($request);
        $user->update(['device_token' => $request->input('deviceToken')]);

        return response()->json(['status' => 'success', 'message' => 'Device token updated successfully.']);
    }

    /**
     * POST /v1/user/set-pin — first-time only; an existing PIN must go
     * through reset-pin, which proves knowledge of the current PIN.
     */
    public function setPin(SetPinRequest $request): JsonResponse
    {
        $user = $this->authUser($request);

        if ($user->hasTransactionPin()) {
            return response()->json(['message' => 'Transaction PIN already set. Use reset-pin to change it.'], 409);
        }

        $this->pins->setPin($user, (string) $request->input('transactionPin'));

        return response()->json(['message' => 'Transaction PIN set successfully.']);
    }

    /**
     * POST /v1/user/verify-pin
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $user = $this->authUser($request);
        $valid = $user->hasTransactionPin() && $this->pins->verify($user, (string) $request->input('transactionPin'));

        if (! $valid) {
            return response()->json(['message' => 'Invalid transaction PIN.'], 401);
        }

        return response()->json(['message' => 'Transaction PIN verified.']);
    }

    /**
     * POST /v1/user/reset-pin
     */
    public function resetPin(ResetPinRequest $request): JsonResponse
    {
        $user = $this->authUser($request);

        if (! $user->hasTransactionPin() || ! $this->pins->verify($user, (string) $request->input('old_pin'))) {
            return response()->json(['message' => 'Old transaction PIN is incorrect.'], 401);
        }

        $this->pins->setPin($user, (string) $request->input('new_pin'));

        return response()->json(['message' => 'Transaction PIN changed successfully.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(User $user): array
    {
        $wallet = $user->wallet;
        $kyc = $user->kycProfile;

        return [
            ...UserResource::make($user)->resolve(),
            'wallet' => [
                'balance' => $wallet !== null
                    ? Money::naira($wallet->availableBalance())->toMajorUnitsString()
                    : '0.00',
            ],
            'kyc' => $kyc !== null ? ['status' => $kyc->status, 'tier' => $kyc->tier] : null,
            // Wired up once the mobile-contract transaction list (Phase 2)
            // and wallet-funding history (Phase 3) exist.
            'transactions' => [],
            'walletFundings' => [],
        ];
    }

    private function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return $user;
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
