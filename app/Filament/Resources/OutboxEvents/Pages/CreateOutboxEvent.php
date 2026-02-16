<?php

namespace App\Filament\Resources\OutboxEvents\Pages;

use App\Filament\Resources\OutboxEvents\OutboxEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOutboxEvent extends CreateRecord
{
    protected static string $resource = OutboxEventResource::class;
}
