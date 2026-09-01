<?php

namespace App\Filament\Pages;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Preferences\Services\PreferenceService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;

/**
 * Public product feature flags + social links, read by mobile clients via
 * GET /v1/preferences. Deliberately its own page, not a tab on Runtime
 * Config: those are infra/provider secrets; these are public, non-secret
 * product toggles — different audience, different blast radius if wrong.
 */
class Preferences extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Preferences';

    protected static string $view = 'filament.pages.preferences';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(app(PreferenceService::class)->get());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Features')
                ->description('Turned off here, a service disappears from the app entirely — as if it never existed.')
                ->components([
                    Toggle::make('features.airtime')->label('Airtime'),
                    Toggle::make('features.data')->label('Data'),
                    Toggle::make('features.sme')->label('SME data'),
                    Toggle::make('features.tv')->label('Cable TV'),
                    Toggle::make('features.electricity')->label('Electricity'),
                    Toggle::make('features.betting')->label('Betting'),
                    Toggle::make('features.giftcard')->label('Gift cards'),
                ])
                ->columns(2),
            Section::make('Socials')
                ->components([
                    TextInput::make('socials.facebook')->label('Facebook'),
                    TextInput::make('socials.instagram')->label('Instagram'),
                    TextInput::make('socials.twitter')->label('Twitter / X'),
                    TextInput::make('socials.whatsapp')->label('WhatsApp'),
                ])
                ->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        app(PreferenceService::class)->set(
            (array) ($state['features'] ?? []),
            (array) ($state['socials'] ?? []),
        );

        app(AuditService::class)->log('admin.preferences.updated', null, Filament::auth()->user());

        Notification::make()->title('Preferences saved')->success()->send();
    }
}
