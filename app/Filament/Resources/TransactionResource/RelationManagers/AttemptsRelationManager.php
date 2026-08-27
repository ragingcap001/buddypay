<?php

namespace App\Filament\Resources\TransactionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Every outbound provider call ProviderGateway made for this transaction,
 * recorded regardless of outcome — the trail support needs when a customer
 * says the provider took their money.
 */
class AttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'attempts';

    protected static ?string $title = 'Provider attempts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('provider.name')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'SUCCESS' => 'success',
                        'FAILURE' => 'danger',
                        'AMBIGUOUS' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $s): string => $s === null ? '—' : "{$s} ms")
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('error')->wrap()->color('danger')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('created_at')
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
