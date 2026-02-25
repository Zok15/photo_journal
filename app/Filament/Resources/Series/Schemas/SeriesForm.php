<?php

namespace App\Filament\Resources\Series\Schemas;

use App\Models\Series;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                TextInput::make('slug')
                    ->maxLength(255),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
                Toggle::make('is_public')
                    ->label('Public')
                    ->default(false),
                Select::make('publication_status')
                    ->label('Publication status')
                    ->options([
                        Series::PUBLICATION_DRAFT => 'draft',
                        Series::PUBLICATION_PENDING_MODERATION => 'pending_moderation',
                        Series::PUBLICATION_PUBLISHED => 'published',
                        Series::PUBLICATION_REJECTED => 'rejected',
                    ])
                    ->required(),
                Select::make('moderation_status')
                    ->label('Moderation status')
                    ->options([
                        Series::MODERATION_PENDING => 'pending',
                        Series::MODERATION_APPROVED => 'approved',
                        Series::MODERATION_REJECTED => 'rejected',
                        Series::MODERATION_MANUAL_APPROVED => 'manual_approved',
                    ])
                    ->required(),
                Textarea::make('moderation_reason')
                    ->label('Moderation reason')
                    ->rows(2)
                    ->columnSpanFull(),
                TagsInput::make('moderation_labels')
                    ->label('Moderation labels')
                    ->columnSpanFull(),
                DateTimePicker::make('publication_requested_at')
                    ->seconds(false)
                    ->native(false),
                DateTimePicker::make('moderated_at')
                    ->seconds(false)
                    ->native(false),
                Select::make('moderated_by')
                    ->relationship('moderator', 'name')
                    ->searchable()
                    ->preload(),
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
