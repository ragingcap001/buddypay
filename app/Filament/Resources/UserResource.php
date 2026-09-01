<?php

namespace App\Filament\Resources;

use App\Domain\Users\Enums\UserStatus;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Customer accounts. Editable only for what support legitimately touches
 * (name, email, account status) — never the PIN or password hash. Not
 * creatable or deletable here: accounts come from registration and are
 * retained for financial record-keeping.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    private static function statusOptions(): array
    {
        return [
            UserStatus::Active->value => 'Active',
            UserStatus::Suspended->value => 'Suspended',
            UserStatus::Closed->value => 'Closed',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            FormSection::make('Profile')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('phone')->disabled()->dehydrated(false),
                Select::make('status')->options(self::statusOptions())->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('email')->searchable()->placeholder('—'),
                Tables\Columns\IconColumn::make('phone_verified_at')->label('Verified')->boolean(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ACTIVE' => 'success',
                        'SUSPENDED' => 'warning',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('wallet.control_balance')
                    ->label('Balance')
                    ->money('NGN', divideBy: 100)
                    ->alignEnd()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('phone')->copyable(),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('status')->badge(),
                TextEntry::make('phone_verified_at')->label('Phone verified')->dateTime()->placeholder('Not verified'),
                TextEntry::make('created_at')->label('Joined')->dateTime(),
            ]),
            Section::make('Wallet')->columns(3)->schema([
                TextEntry::make('wallet.currency')->label('Currency')->placeholder('—'),
                TextEntry::make('wallet.control_balance')->label('Balance')->money('NGN', divideBy: 100)->placeholder('—'),
                TextEntry::make('wallet.reserved_balance')->label('Reserved')->money('NGN', divideBy: 100)->placeholder('—'),
            ]),
            Section::make('KYC')->columns(2)->schema([
                TextEntry::make('kycProfile.tier')->label('Tier')->placeholder('—'),
                TextEntry::make('kycProfile.status')->label('Status')->badge()->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
