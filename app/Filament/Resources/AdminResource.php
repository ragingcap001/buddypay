<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminResource\Pages;
use App\Models\Admin;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * Staff accounts for this panel. Separate from the customer User model —
 * see the create_admins_table migration.
 */
class AdminResource extends Resource
{
    protected static ?string $model = Admin::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'staff account';

    protected static ?string $pluralModelLabel = 'staff accounts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(12)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->helperText(fn (string $operation): string => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : 'Minimum 12 characters.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('created_at')->label('Added')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    // Locking yourself out of the only admin panel is a bad afternoon.
                    ->hidden(fn (Admin $record): bool => $record->is(Filament::auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdmins::route('/'),
            'create' => Pages\CreateAdmin::route('/create'),
            'edit' => Pages\EditAdmin::route('/{record}/edit'),
        ];
    }
}
