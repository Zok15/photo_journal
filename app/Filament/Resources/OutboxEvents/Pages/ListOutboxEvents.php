<?php

namespace App\Filament\Resources\OutboxEvents\Pages;

use App\Filament\Resources\OutboxEvents\OutboxEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutboxEvents extends ListRecords
{
    protected static string $resource = OutboxEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
