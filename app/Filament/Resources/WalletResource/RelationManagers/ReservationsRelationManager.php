<?php

namespace App\Filament\Resources\WalletResource\RelationManagers;

use App\Models\WalletReservation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ReservationsRelationManager extends RelationManager
{
    protected static string $relationship = 'reservations';

    protected static ?string $title = 'Reservations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                Tables\Columns\TextColumn::make('reference')->searchable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn (WalletReservation $r) => $r->wallet?->currency ?? 'NGN', divideBy: 100)
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ACTIVE' => 'warning',
                        'COMMITTED' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->since(),
                Tables\Columns\TextColumn::make('release_reason')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
