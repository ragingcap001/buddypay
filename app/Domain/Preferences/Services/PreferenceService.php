<?php

namespace App\Domain\Preferences\Services;

use App\Models\Preference;

/**
 * Public product feature flags + social links (GET /v1/preferences), plus
 * the admin-editable versions of the same. `bettingCharge` is intentionally
 * absent here — it's read live from config('ase.fees.betting.flat'), the
 * exact figure FeeCalculation already charges, so there is only one place
 * that number can live.
 */
final class PreferenceService
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        $row = $this->row();

        return [
            'features' => (array) $row->features,
            'socials' => (array) $row->socials,
        ];
    }

    public function bettingChargeNaira(): int
    {
        // intdiv, not `/` — PHP's `/` returns a float for a non-evenly-
        // divisible kobo amount, which would silently truncate when
        // coerced back to this method's `int` return type.
        return intdiv((int) config('ase.fees.betting.flat', 0), 100);
    }

    /**
     * @param  array<string, bool>  $features
     * @param  array<string, string>  $socials
     */
    public function set(array $features, array $socials): void
    {
        $row = $this->row();

        $row->update([
            'features' => array_merge((array) $row->features, $features),
            'socials' => array_merge((array) $row->socials, $socials),
        ]);
    }

    private function row(): Preference
    {
        return Preference::query()->firstOrCreate(['id' => 1], [
            'features' => [],
            'socials' => [],
        ]);
    }
}
