<?php

namespace App\Filament\Resources\OutboxEvents\Pages;

use App\Filament\Resources\OutboxEvents\OutboxEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOutboxEvent extends EditRecord
{
    protected static string $resource = OutboxEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
