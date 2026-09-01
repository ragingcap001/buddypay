<?php

namespace App\Filament\Resources;

use App\Domain\Transactions\Enums\TransactionType;
use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Resources\TransactionResource\RelationManagers;
use App\Models\Provider;
use App\Models\Transaction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;

/**
 * Read-only. A transaction's status only ever moves through
 * TransactionStateMachine via the domain services — there is deliberately
 * no admin edit path, because an out-of-band status change would leave the
 * ledger and the transaction disagreeing.
 */
class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Money';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'reference';

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'COMPLETED', 'SUCCESS' => 'success',
            'FAILED' => 'danger',
            'PENDING', 'PROCESSING', 'AMBIGUOUS', 'VERIFYING' => 'warning',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->description(fn (Transaction $r): string => (string) $r->user?->phone),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('amount')
                    ->money(fn (Transaction $r) => $r->currency, divideBy: 100)
                    ->alignEnd()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fee')
                    ->money(fn (Transaction $r) => $r->currency, divideBy: 100)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('provider')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'INITIATED' => 'Initiated',
                    'PENDING' => 'Pending',
                    'PROCESSING' => 'Processing',
                    'AMBIGUOUS' => 'Ambiguous',
                    'VERIFYING' => 'Verifying',
                    'SUCCESS' => 'Success',
                    'COMPLETED' => 'Completed',
                    'FAILED' => 'Failed',
                    'REVERSED' => 'Reversed',
                ]),
                Tables\Filters\SelectFilter::make('type')->options(
                    collect(TransactionType::cases())->mapWithKeys(
                        fn (TransactionType $t) => [$t->value => $t->name],
                    )->all(),
                ),
                Tables\Filters\SelectFilter::make('provider')->options(
                    fn (): array => Provider::query()->pluck('name', 'name')->all(),
                ),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Transaction')
                ->columns(3)
                ->components([
                    TextEntry::make('reference')->copyable()->fontFamily('mono'),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('provider')->placeholder('—'),
                    TextEntry::make('provider_reference')->label('Provider ref')->copyable()->placeholder('—'),
                    TextEntry::make('amount')->money(fn (Transaction $r) => $r->currency, divideBy: 100),
                    TextEntry::make('fee')->money(fn (Transaction $r) => $r->currency, divideBy: 100),
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                ]),
            Section::make('Metadata')
                ->components([
                    TextEntry::make('metadata')
                        ->label('')
                        ->formatStateUsing(fn (?array $state): string => json_encode($state ?? [], JSON_PRETTY_PRINT))
                        ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;'])
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EventsRelationManager::class,
            RelationManagers\AttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'view' => Pages\ViewTransaction::route('/{record}'),
        ];
    }

    // v4 calls the get*AuthorizationResponse() methods instead of can*().
    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny('Transactions are created by the API, not in the panel.');
    }
}
