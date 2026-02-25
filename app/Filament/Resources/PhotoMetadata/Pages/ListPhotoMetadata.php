<?php

namespace App\Filament\Resources\PhotoMetadata\Pages;

use App\Filament\Resources\PhotoMetadata\PhotoMetadataResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPhotoMetadata extends ListRecords
{
    protected static string $resource = PhotoMetadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

