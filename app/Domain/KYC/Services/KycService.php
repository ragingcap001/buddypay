<?php

namespace App\Domain\KYC\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\KYC\Enums\KycStatus;
use App\Domain\KYC\Enums\KycTier;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Models\KycProfile;
use App\Models\KycVerification;
use App\Models\User;

/**
 * KYC orchestration.
 *
 * The real integration (BVN/NIN lookup providers) sits behind a provider
 * call; in this scaffold submission is treated as a synchronous mock
 * verification so the tier + limits flow is fully exercisable.
 */
final class KycService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function profileFor(User $user): ?KycProfile
    {
        return KycProfile::where('user_id', $user->id)->first();
    }

    public function ensureProfile(User $user): KycProfile
    {
        $profile = $this->profileFor($user);

        if ($profile !== null) {
            return $profile;
        }

        return KycProfile::create([
            'user_id' => $user->id,
            'status' => KycStatus::Pending->value,
            'tier' => KycTier::Unverified->value,
        ]);
    }

    /**
     * Submit BVN verification.
     */
    public function submitBvn(User $user, string $bvn): KycProfile
    {
        $profile = $this->ensureProfile($user);

        $verification = KycVerification::create([
            'kyc_profile_id' => $profile->id,
            'reference' => ReferenceGenerator::kycVerification(),
            'type' => 'BVN',
            'status' => KycStatus::Submitted->value,
            'input_hash' => hash('sha256', $bvn),
        ]);

        // Mock provider decision: BVNs ending in an odd digit verify.
        $lastDigit = (int) substr($bvn, -1, 1);
        $verified = $lastDigit % 2 === 1;

        $status = $verified ? KycStatus::Verified : KycStatus::Failed;
        $tier = $verified ? KycTier::Basic->value : $profile->tier;

        $verification->update([
            'status' => $status->value,
            'provider_response' => ['mock' => true, 'verified' => $verified],
        ]);

        $profile->update([
            'status' => $status->value,
            'tier' => $tier,
            'bvn_hash' => hash('sha256', $bvn),
            'full_name' => 'Mock BVN Holder',
        ]);

        $this->audit->log('kyc.bvn_submitted', $profile, $user, ['verified' => $verified]);

        return $profile->refresh();
    }

    /**
     * Submit NIN verification (promotes to the full KYC tier).
     */
    public function submitNin(User $user, string $nin): KycProfile
    {
        $profile = $this->ensureProfile($user);

        $verification = KycVerification::create([
            'kyc_profile_id' => $profile->id,
            'reference' => ReferenceGenerator::kycVerification(),
            'type' => 'NIN',
            'status' => KycStatus::Submitted->value,
            'input_hash' => hash('sha256', $nin),
        ]);

        $verified = strlen($nin) >= 11;
        $status = $verified ? KycStatus::Verified : KycStatus::Failed;
        $tier = $verified ? KycTier::Full->value : $profile->tier;

        $verification->update([
            'status' => $status->value,
            'provider_response' => ['mock' => true, 'verified' => $verified],
        ]);

        $profile->update([
            'status' => $status->value,
            'tier' => $tier,
            'nin_hash' => hash('sha256', $nin),
        ]);

        $this->audit->log('kyc.nin_submitted', $profile, $user, ['verified' => $verified]);

        return $profile->refresh();
    }
}
