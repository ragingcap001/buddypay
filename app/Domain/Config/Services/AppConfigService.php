<?php

namespace App\Domain\Config\Services;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Runtime configuration management for the admin dashboard.
 *
 * Resolution order: DB override (encrypted `app_config` row) -> environment
 * variable -> manifest default. Setting a value to null deletes the row and
 * restores the env/default behaviour.
 *
 * Providers (WemaClient, MonnifyClient, the webhook receiver) read their
 * credentials through this service, so dashboard values take effect without
 * a redeploy.
 */
final class AppConfigService
{
    /**
     * The manifest from config/app_config.php.
     *
     * @return array<string, array{label: string, keys: array<string, array>}>
     */
    public function manifest(): array
    {
        return (array) config('app_config.groups', []);
    }

    public function isKnownKey(string $group, string $key): bool
    {
        return isset($this->manifest()[$group]['keys'][$key]);
    }

    public function isSecret(string $group, string $key): bool
    {
        return (bool) ($this->manifest()[$group]['keys'][$key]['secret'] ?? false);
    }

    /**
     * Effective value: DB override -> config (which itself loads from the
     * environment) -> manifest default.
     */
    public function get(string $group, string $key): ?string
    {
        $row = AppConfig::where('key', "{$group}.{$key}")->first();

        if ($row !== null && $row->value !== null && $row->value !== '') {
            return (string) $row->value;
        }

        $definition = $this->manifest()[$group]['keys'][$key] ?? null;

        if ($definition === null) {
            return null;
        }

        // Config key (e.g. ase.monnify.api_key) — the canonical runtime
        // source; it loads from the environment variable in production and
        // is overridable in tests. Falls back to raw env for keys that have
        // no ase.* config entry (firebase / apple / google).
        $configKey = (string) ($definition['config'] ?? '');

        if ($configKey !== '' && config($configKey) !== null && config($configKey) !== '') {
            return (string) config($configKey);
        }

        $env = (string) ($definition['env'] ?? '');

        if ($configKey === '' && $env !== '' && env($env) !== null && env($env) !== '') {
            return (string) env($env);
        }

        $default = $definition['default'] ?? null;

        return $default === null ? null : (string) $default;
    }

    /**
     * @return array<string, mixed>  group => key => {value, masked, overridden, env, label, secret}
     */
    public function all(?string $group = null): array
    {
        $result = [];

        foreach ($this->manifest() as $name => $definition) {
            if ($group !== null && $name !== $group) {
                continue;
            }

            $rows = AppConfig::where('group', $name)->get()->keyBy('key');

            foreach ($definition['keys'] as $key => $keyDefinition) {
                $row = $rows->get("{$name}.{$key}");
                $overridden = $row !== null && $row->value !== null && $row->value !== '';
                $value = $overridden
                    ? (string) $row->value
                    : $this->get($name, $key);

                $secret = (bool) ($keyDefinition['secret'] ?? false);

                $result[$name][$key] = [
                    'label' => $keyDefinition['label'] ?? $key,
                    'env' => $keyDefinition['env'] ?? null,
                    'secret' => $secret,
                    'multiline' => (bool) ($keyDefinition['multiline'] ?? false),
                    'overridden' => $overridden,
                    // Secrets are NEVER returned in full — only masked.
                    'value' => $secret ? null : $value,
                    'masked' => $secret ? $this->mask($value) : $value,
                    'updated_by' => $row?->updatedBy?->email ?? $row?->updatedBy?->name,
                    'updated_at' => $row?->updated_at?->toIso8601String(),
                ];
            }
        }

        return $result;
    }

    /**
     * Set (or clear, when $value is null) a configuration value.
     *
     * @param  array<string, string|null>  $values  key => new value
     * @return array<string, string>  the keys applied
     */
    public function set(string $group, array $values, ?User $by = null): array
    {
        $applied = [];

        foreach ($values as $key => $value) {
            if (! $this->isKnownKey($group, $key)) {
                continue; // never persist unknown keys
            }

            $normalized = $value === null ? null : trim((string) $value);

            if ($normalized === '') {
                $normalized = null;
            }

            $row = AppConfig::firstOrCreate([
                'key' => "{$group}.{$key}",
            ], [
                'group' => $group,
                'is_secret' => $this->isSecret($group, $key),
            ]);

            $row->forceFill([
                'value' => $normalized,
                'updated_by' => $by?->id,
            ])->save();

            $applied[] = "{$group}.{$key}";
        }

        return $applied;
    }

    /**
     * Mask a secret for display: "sk…wxyz" (last 4 chars only).
     */
    public function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $length = strlen($value);

        if ($length <= 4) {
            return '••••';
        }

        return '••••'.substr($value, -4);
    }
}
