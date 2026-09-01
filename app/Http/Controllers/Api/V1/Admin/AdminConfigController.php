<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Config\Services\AppConfigService;
use App\Domain\Providers\Enums\ProviderAttemptStatus;
use App\Domain\Providers\Services\CircuitBreaker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateConfigRequest;
use App\Http\Support\ApiResponse;
use App\Models\Provider;
use App\Models\ProviderAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin dashboard API (web-session auth + admin role).
 *
 * - GET  /api/v1/admin/config          — manifest with effective values
 * - PUT  /api/v1/admin/config          — set/clear config values
 * - GET  /api/v1/admin/providers       — provider health panel
 */
class AdminConfigController extends Controller
{
    public function __construct(
        private readonly AppConfigService $appConfig,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * GET /api/v1/admin/config?group=monnify
     */
    public function index(Request $request): JsonResponse
    {
        $group = $request->query('group') !== null ? (string) $request->query('group') : null;

        return ApiResponse::success($this->appConfig->all($group));
    }

    /**
     * PUT /api/v1/admin/config  { "group": "wema", "values": { "api_key": "..." , "webhook": null } }
     *
     * null clears a DB override (falls back to the environment variable).
     */
    public function update(UpdateConfigRequest $request): JsonResponse
    {
        $group = (string) $request->input('group');
        $values = (array) $request->input('values', []);

        $applied = $this->appConfig->set($group, $values, $request->user());

        // Credential/config changes are security-relevant: record WHO changed
        // WHICH keys (never the values — secrets must not reach the audit
        // trail or the log).
        $this->audit->log('config.updated', null, $request->user(), [
            'group' => $group,
            'keys' => array_map(fn (string $k): string => (string) (explode('.', $k, 2)[1] ?? $k), $applied),
        ]);

        return ApiResponse::success(
            ['applied' => $applied],
            count($applied) === 0 ? 'Nothing to update (unknown keys ignored).' : 'Configuration updated.',
        );
    }

    /**
     * GET /api/v1/admin/providers — status, circuit breaker and 24h attempt
     * counts per registered provider, plus which config keys are overridden
     * in the DB.
     */
    public function providers(): JsonResponse
    {
        $providers = Provider::orderBy('name')->get()->map(function (Provider $provider) {
            $since = now()->subDay();

            $counts = ProviderAttempt::where('provider_id', $provider->id)
                ->where('created_at', '>=', $since)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            return [
                'name' => $provider->name,
                'type' => $provider->type,
                'display_name' => $provider->display_name,
                'status' => $provider->status,
                'circuit' => $this->circuitBreaker->state($provider->name)->value,
                'attempts_24h' => [
                    'success' => (int) ($counts[ProviderAttemptStatus::Success->value] ?? 0),
                    'failure' => (int) ($counts[ProviderAttemptStatus::Failure->value] ?? 0),
                    'ambiguous' => (int) ($counts[ProviderAttemptStatus::Ambiguous->value] ?? 0),
                ],
            ];
        });

        return ApiResponse::success(['providers' => $providers]);
    }
}
