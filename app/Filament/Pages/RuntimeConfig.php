<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Config\Services\AppConfigService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Runtime provider/config editor — the Filament port of the Blade admin
 * dashboard's config panel.
 *
 * Behaviour intentionally preserved from AdminConfigController:
 *  - resolution order stays DB override -> env -> default (AppConfigService),
 *  - secret values are never rendered back to the browser, only their mask,
 *  - an empty submitted secret means "leave unchanged", not "clear",
 *  - every save is audit-logged with the actor and the keys touched,
 *    never the values.
 */
class RuntimeConfig extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Runtime config';

    protected static string $view = 'filament.pages.runtime-config';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    /**
     * Non-secret values are pre-filled; secret inputs always start empty so
     * the real value never reaches the browser.
     *
     * @return array<string, mixed>
     */
    private function currentState(): array
    {
        $state = [];

        foreach (app(AppConfigService::class)->all() as $group => $keys) {
            foreach ($keys as $key => $meta) {
                $state[$group][$key] = $meta['secret'] ? null : $meta['value'];
            }
        }

        return $state;
    }

    public function form(Form $form): Form
    {
        $config = app(AppConfigService::class);
        $all = $config->all();
        $sections = [];

        foreach ($config->manifest() as $group => $definition) {
            $fields = [];

            foreach ($definition['keys'] as $key => $keyDefinition) {
                $meta = $all[$group][$key] ?? [];
                $isSecret = (bool) ($keyDefinition['secret'] ?? false);
                $isMultiline = (bool) ($keyDefinition['multiline'] ?? false);
                $label = $keyDefinition['label'] ?? $key;

                $hint = ($meta['overridden'] ?? false)
                    ? 'Overridden in DB'
                    : 'From '.($keyDefinition['env'] ?? 'default');

                if ($isSecret) {
                    $fields[] = TextInput::make("{$group}.{$key}")
                        ->label($label)
                        ->password()
                        ->revealable()
                        ->autocomplete('new-password')
                        ->placeholder($meta['masked'] ?? '—')
                        ->helperText('Leave blank to keep the current value.')
                        ->hint($hint);

                    continue;
                }

                $fields[] = $isMultiline
                    ? Textarea::make("{$group}.{$key}")->label($label)->rows(4)->hint($hint)
                    : TextInput::make("{$group}.{$key}")->label($label)->hint($hint);
            }

            $sections[] = Section::make($definition['label'] ?? $group)
                ->description('Saved values override the environment without a redeploy.')
                ->schema($fields)
                ->collapsible()
                ->collapsed();
        }

        return $form->schema($sections)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $config = app(AppConfigService::class);
        $admin = Filament::auth()->user();

        $appliedByGroup = [];

        foreach ($state as $group => $values) {
            if (! is_array($values)) {
                continue;
            }

            $payload = [];

            foreach ($values as $key => $value) {
                // A blank secret means "unchanged" — never clear a
                // credential just because the input rendered empty.
                if ($config->isSecret($group, $key) && ($value === null || $value === '')) {
                    continue;
                }

                $payload[$key] = $value;
            }

            if ($payload === []) {
                continue;
            }

            // $by stays null: app_config.updated_by is a FK to `users`, and a
            // staff Admin id there would point at an unrelated customer.
            // Attribution lives in the audit log below instead.
            $applied = $config->set($group, $payload, null);

            if ($applied !== []) {
                $appliedByGroup[$group] = $applied;
            }
        }

        if ($appliedByGroup === []) {
            Notification::make()->title('Nothing to save')->warning()->send();

            return;
        }

        app(AuditService::class)->log(
            'admin.config.updated',
            null,
            $admin,
            // Keys only — values (especially secrets) must never be audited.
            ['groups' => array_keys($appliedByGroup), 'keys' => array_merge(...array_values($appliedByGroup))],
        );

        $this->form->fill($this->currentState());

        Notification::make()->title('Configuration saved')->success()->send();
    }
}
