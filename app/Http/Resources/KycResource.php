<?php

namespace App\Http\Resources;

use App\Domain\KYC\Enums\KycTier;
use App\Models\KycProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KycProfile
 */
class KycResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = KycTier::tryFrom((int) $this->tier) ?? KycTier::Unverified;

        return [
            'status' => $this->status,
            'tier' => $tier->value,
            'tier_name' => match ($tier) {
                KycTier::Unverified => 'Unverified',
                KycTier::Basic => 'Basic (BVN)',
                KycTier::Full => 'Full (NIN)',
            },
            'full_name' => $this->full_name,
            'limits' => $tier->limits(),
        ];
    }
}
