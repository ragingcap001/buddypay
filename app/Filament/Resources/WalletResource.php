<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletResource\Pages;
use App\Models\Wallet;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

/**
 * Read-only: balances are only correct as a side effect of the ledger.
 * Hand-editing them here would silently break double-entry integrity.
 */
class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Wallet $r): string => (string) $r->user?->phone),
                Tables\Columns\TextColumn::make('currency')->badge(),
                Tables\Columns\TextColumn::make('control_balance')
                    ->label('Control')
                    ->money(fn (Wallet $r) => $r->currency, divideBy: 100)
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reserved_balance')
                    ->label('Reserved')
                    ->money(fn (Wallet $r) => $r->currency, divideBy: 100)
                    ->alignEnd()
                    ->color(fn (Wallet $r): string => $r->reserved_balance > 0 ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('available')
                    ->label('Available')
                    ->state(fn (Wallet $r): int => $r->availableBalance())
                    ->money(fn (Wallet $r) => $r->currency, divideBy: 100)
                    ->alignEnd()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('updated_at')->label('Last activity')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('has_reservation')
                    ->label('Has an active reservation')
                    ->query(fn ($q) => $q->where('reserved_balance', '>', 0)),
            ])
            ->recordActions([Actions\ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Wallet')->columns(2)->components([
                TextEntry::make('user.name')->label('Customer'),
                TextEntry::make('user.phone')->label('Phone'),
                TextEntry::make('currency'),
                TextEntry::make('created_at')->dateTime(),
            ]),
            Section::make('Balances')->columns(3)->components([
                TextEntry::make('control_balance')->label('Control')->money(fn (Wallet $r) => $r->currency, divideBy: 100),
                TextEntry::make('reserved_balance')->label('Reserved')->money(fn (Wallet $r) => $r->currency, divideBy: 100),
                TextEntry::make('available')
                    ->state(fn (Wallet $r): int => $r->availableBalance())
                    ->money(fn (Wallet $r) => $r->currency, divideBy: 100)
                    ->weight('bold'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [WalletResource\RelationManagers\ReservationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWallets::route('/'),
            'view' => Pages\ViewWallet::route('/{record}'),
        ];
    }

    // v4 calls the get*AuthorizationResponse() methods instead of can*().
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny('Wallets are created with the customer, not in the panel.');
    }
}
