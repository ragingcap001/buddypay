<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletResource\Pages;
use App\Models\Wallet;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only: balances are only correct as a side effect of the ledger.
 * Hand-editing them here would silently break double-entry integrity.
 */
class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $navigationGroup = 'Money';

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
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Wallet')->columns(2)->schema([
                TextEntry::make('user.name')->label('Customer'),
                TextEntry::make('user.phone')->label('Phone'),
                TextEntry::make('currency'),
                TextEntry::make('created_at')->dateTime(),
            ]),
            Section::make('Balances')->columns(3)->schema([
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

    public static function canCreate(): bool
    {
        return false;
    }
}
