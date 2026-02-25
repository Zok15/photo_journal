<?php

namespace App\Filament\Resources\Photos\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PhotoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('series_id')
                    ->relationship('series', 'title')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('path')
                    ->label('Original Path')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('preview_path')
                    ->label('Preview Path')
                    ->maxLength(255)
                    ->columnSpanFull(),
                TextInput::make('original_name')
                    ->maxLength(255),
                TextInput::make('mime')
                    ->maxLength(255),
                TextInput::make('size')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('sort_order')
                    ->numeric()
                    ->minValue(0),
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
