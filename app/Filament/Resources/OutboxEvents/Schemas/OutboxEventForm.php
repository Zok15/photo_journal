<?php

namespace App\Filament\Resources\OutboxEvents\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OutboxEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('status')
                    ->options([
                        'pending' => 'pending',
                        'processing' => 'processing',
                        'done' => 'done',
                        'failed' => 'failed',
                    ])
                    ->required(),
                TextInput::make('attempts')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                DateTimePicker::make('available_at'),
                DateTimePicker::make('processed_at'),
                KeyValue::make('payload')
                    ->columnSpanFull(),
                Textarea::make('last_error')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
