<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'fpuid' => $this->fpuid,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'deviceToken' => $this->device_token,
            'emailVerifiedAt' => $this->email_verified_at?->toIso8601String(),
            'isEmailVerified' => $this->email_verified_at !== null,
            'hasTransactionPin' => $this->hasTransactionPin(),
        ];
    }
}
