<?php

namespace App\Filament\Resources;

use App\Domain\Providers\Services\CircuitBreaker;
use App\Filament\Resources\ProviderResource\Pages;
use App\Models\Provider;
use App\Models\ProviderAttempt;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

/**
 * Provider health, and the one operational lever worth having in a UI:
 * flipping a provider to DISABLED during an incident (the manual
 * equivalent of tripping its circuit breaker). The provider set itself is
 * seeded/migrated, not managed here.
 */
class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')->columns(2)->components([
                TextInput::make('display_name')->disabled()->dehydrated(false),
                TextInput::make('name')->disabled()->dehydrated(false),
                TextInput::make('type')->disabled()->dehydrated(false),
                Select::make('status')
                    ->options([
                        Provider::STATUS_ACTIVE => 'Active',
                        Provider::STATUS_DISABLED => 'Disabled',
                    ])
                    ->required(),
                TextInput::make('priority')
                    ->numeric()
                    ->required()
                    ->helperText('Lower runs first when several providers can serve a request.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Provider')
                    ->description(fn (Provider $r): string => $r->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === Provider::STATUS_ACTIVE ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('circuit')
                    ->label('Circuit')
                    ->state(fn (Provider $r): string => app(CircuitBreaker::class)->state($r->name)->value)
                    ->badge()
                    ->color(fn (string $state): string => match (strtoupper($state)) {
                        'CLOSED' => 'success',
                        'HALF_OPEN' => 'warning',
                        'OPEN' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('attempts_24h')
                    ->label('Attempts (24h)')
                    ->state(fn (Provider $r): int => ProviderAttempt::where('provider_id', $r->id)
                        ->where('created_at', '>=', now()->subDay())
                        ->count())
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('failures_24h')
                    ->label('Failures (24h)')
                    ->state(fn (Provider $r): int => ProviderAttempt::where('provider_id', $r->id)
                        ->where('created_at', '>=', now()->subDay())
                        ->where('status', 'FAILURE')
                        ->count())
                    ->alignEnd()
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('priority')->sortable(),
            ])
            ->defaultSort('priority')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Provider::STATUS_ACTIVE => 'Active',
                    Provider::STATUS_DISABLED => 'Disabled',
                ]),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider')->columns(3)->components([
                TextEntry::make('display_name')->label('Name'),
                TextEntry::make('name')->label('Key'),
                TextEntry::make('type')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('priority'),
                TextEntry::make('base_url')->label('Base URL')->copyable()->placeholder('—'),
            ]),
            Section::make('Config')
                ->components([
                    TextEntry::make('config')
                        ->label('')
                        ->formatStateUsing(function (mixed $state): string {
                            $decoded = is_string($state) ? json_decode($state, true) : $state;

                            return json_encode($decoded ?? [], JSON_PRETTY_PRINT);
                        })
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;'])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviders::route('/'),
            'view' => Pages\ViewProvider::route('/{record}'),
            'edit' => Pages\EditProvider::route('/{record}/edit'),
        ];
    }

    // Providers are seeded/migrated, not created in the panel. v4 calls the
    // get*AuthorizationResponse() methods instead of can*().
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny('Providers are seeded by migrations, not created in the panel.');
    }
}
