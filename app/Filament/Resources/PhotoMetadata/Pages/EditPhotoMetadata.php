<?php

namespace App\Filament\Resources\PhotoMetadata\Pages;

use App\Filament\Resources\PhotoMetadata\PhotoMetadataResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPhotoMetadata extends EditRecord
{
    protected static string $resource = PhotoMetadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

