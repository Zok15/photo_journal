<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('journal_title')
                    ->maxLength(255),
                Select::make('locale')
                    ->options([
                        'ru' => 'ru',
                        'en' => 'en',
                    ])
                    ->required(),
                Toggle::make('email_verified')
                    ->label('Email verified')
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Toggle $component, $state, $record): void {
                        unset($state);

                        $component->state(filled($record?->email_verified_at));
                    })
                    ->live()
                    ->afterStateUpdated(function (bool $state, callable $set): void {
                        if (! $state) {
                            $set('email_verified_at', null);
                            return;
                        }

                        $set('email_verified_at', now());
                    }),
                DateTimePicker::make('email_verified_at')
                    ->label('Email verified at')
                    ->seconds(false)
                    ->native(false)
                    ->nullable()
                    ->dehydrateStateUsing(static fn ($state) => blank($state) ? null : $state),
                DateTimePicker::make('personal_data_consent_at')
                    ->label('Personal data consent at')
                    ->seconds(false)
                    ->native(false)
                    ->nullable()
                    ->dehydrateStateUsing(static fn ($state) => blank($state) ? null : $state),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->minLength(8)
                    ->maxLength(255)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                TextInput::make('remember_token')
                    ->maxLength(100),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                DateTimePicker::make('created_at')
                    ->seconds(false)
                    ->native(false)
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('updated_at')
                    ->seconds(false)
                    ->native(false)
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
