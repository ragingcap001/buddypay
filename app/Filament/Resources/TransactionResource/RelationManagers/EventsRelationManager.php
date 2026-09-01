<?php

namespace App\Filament\Resources\TransactionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Append-only transition log written by TransactionService::transition().
 * The answer to "how did this transaction reach its current status".
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Status history';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_status')
            ->columns([
                Tables\Columns\TextColumn::make('from_status')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('to_status')->badge(),
                Tables\Columns\TextColumn::make('reason')->wrap(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('created_at');
    }
}
