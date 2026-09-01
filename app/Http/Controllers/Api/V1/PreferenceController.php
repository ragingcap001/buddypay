<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Preferences\Services\PreferenceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public feature flags + socials — used by the mobile app to hide/show
 * services without a client release. No auth: the client needs this
 * before a user has even logged in.
 */
class PreferenceController extends Controller
{
    public function __construct(private readonly PreferenceService $preferences)
    {
    }

    /**
     * GET /v1/preferences
     */
    public function show(): JsonResponse
    {
        $preferences = $this->preferences->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'features' => $preferences['features'],
                'socials' => $preferences['socials'],
                'bettingCharge' => $this->preferences->bettingChargeNaira(),
            ],
        ]);
    }
}
