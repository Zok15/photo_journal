<?php

namespace App\Filament\Resources\PhotoMetadata\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PhotoMetadataForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('photo_id')
                    ->relationship('photo', 'original_name')
                    ->searchable()
                    ->preload()
                    ->required(),
                DateTimePicker::make('taken_at')
                    ->seconds(false)
                    ->native(false),
                TextInput::make('camera_make')
                    ->maxLength(255),
                TextInput::make('camera_model')
                    ->maxLength(255),
                TextInput::make('lens_model')
                    ->maxLength(255),
                TextInput::make('iso')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('exposure_time')
                    ->maxLength(64),
                TextInput::make('aperture')
                    ->numeric()
                    ->step(0.01),
                TextInput::make('focal_length_mm')
                    ->numeric()
                    ->step(0.01),
                TextInput::make('width')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('height')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('orientation')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('latitude')
                    ->numeric()
                    ->step(0.0000001),
                TextInput::make('longitude')
                    ->numeric()
                    ->step(0.0000001),
                TextInput::make('altitude_m')
                    ->numeric()
                    ->step(0.01),
                Select::make('flash_fired')
                    ->options([
                        1 => 'yes',
                        0 => 'no',
                    ]),
                TextInput::make('white_balance_mode')
                    ->maxLength(32),
                TextInput::make('color_space')
                    ->maxLength(32),
                TextInput::make('source_file_size')
                    ->numeric()
                    ->minValue(0),
                Textarea::make('raw_exif_json')
                    ->label('Raw EXIF JSON')
                    ->formatStateUsing(static fn ($state): string => is_array($state)
                        ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
                        : (string) ($state ?? ''))
                    ->dehydrateStateUsing(static function ($state): ?array {
                        $value = trim((string) $state);
                        if ($value === '') {
                            return null;
                        }

                        $decoded = json_decode($value, true);
                        return is_array($decoded) ? $decoded : null;
                    })
                    ->rows(8)
                    ->columnSpanFull(),
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
