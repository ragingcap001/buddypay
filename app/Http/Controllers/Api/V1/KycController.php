<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\KYC\Enums\KycStatus;
use App\Domain\KYC\Enums\KycTier;
use App\Domain\KYC\Services\KycService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\SubmitBvnRequest;
use App\Http\Requests\Kyc\SubmitNinRequest;
use App\Http\Resources\KycResource;
use App\Http\Support\ApiResponse;
use App\Models\KycDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(private readonly KycService $kyc)
    {
    }

    /**
     * GET /api/v1/kyc
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $profile = $this->kyc->profileFor($user);

        if ($profile === null) {
            $tier = KycTier::Unverified;

            return ApiResponse::success([
                'status' => KycStatus::Pending->value,
                'tier' => $tier->value,
                'tier_name' => 'Unverified',
                'full_name' => null,
                'limits' => $tier->limits(),
            ]);
        }

        return ApiResponse::success(new KycResource($profile));
    }

    /**
     * POST /api/v1/kyc/bvn
     */
    public function bvn(SubmitBvnRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $profile = $this->kyc->submitBvn($user, (string) $request->input('bvn'));

        return ApiResponse::success(new KycResource($profile), 'BVN verification processed');
    }

    /**
     * POST /api/v1/kyc/nin
     */
    public function nin(SubmitNinRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');

        $profile = $this->kyc->submitNin($user, (string) $request->input('nin'));

        return ApiResponse::success(new KycResource($profile), 'NIN verification processed');
    }

    /**
     * POST /api/v1/kyc/documents
     */
    public function documents(Request $request): JsonResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'type' => ['sometimes', 'string', 'max:40'],
        ]);

        $user = $request->user('sanctum');
        $profile = $this->kyc->ensureProfile($user);
        $file = $request->file('document');

        $path = $file->store('kyc/'.$user->id, 'local');

        $document = KycDocument::create([
            'kyc_profile_id' => $profile->id,
            'type' => (string) ($request->input('type') ?? 'IDENTITY'),
            'storage_path' => $path,
            'status' => 'PENDING',
        ]);

        return ApiResponse::success([
            'id' => $document->id,
            'type' => $document->type,
            'status' => $document->status,
        ], 'Document uploaded', 201);
    }
}
