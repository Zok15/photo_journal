<?php

namespace App\Filament\Resources\OutboxEvents;

use App\Filament\Resources\OutboxEvents\Pages\EditOutboxEvent;
use App\Filament\Resources\OutboxEvents\Pages\ListOutboxEvents;
use App\Filament\Resources\OutboxEvents\Schemas\OutboxEventForm;
use App\Filament\Resources\OutboxEvents\Tables\OutboxEventsTable;
use App\Models\OutboxEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OutboxEventResource extends Resource
{
    protected static ?string $model = OutboxEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return 'Outbox Events';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Operations';
    }

    public static function form(Schema $schema): Schema
    {
        return OutboxEventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutboxEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboxEvents::route('/'),
            'edit' => EditOutboxEvent::route('/{record}/edit'),
        ];
    }
}
