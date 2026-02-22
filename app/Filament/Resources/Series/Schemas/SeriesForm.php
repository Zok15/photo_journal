<?php

namespace App\Filament\Resources\Series\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SeriesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
                Toggle::make('is_public')
                    ->label('Public')
                    ->default(false),
                TextInput::make('publication_status')
                    ->label('Publication status')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('moderation_status')
                    ->label('Moderation status')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('moderation_reason')
                    ->label('Moderation reason')
                    ->rows(2)
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }
}
