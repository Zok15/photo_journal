<?php

namespace App\Filament\Resources\PhotoMetadata;

use App\Filament\Resources\PhotoMetadata\Pages\CreatePhotoMetadata;
use App\Filament\Resources\PhotoMetadata\Pages\EditPhotoMetadata;
use App\Filament\Resources\PhotoMetadata\Pages\ListPhotoMetadata;
use App\Filament\Resources\PhotoMetadata\Schemas\PhotoMetadataForm;
use App\Filament\Resources\PhotoMetadata\Tables\PhotoMetadataTable;
use App\Models\PhotoMetadata;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PhotoMetadataResource extends Resource
{
    protected static ?string $model = PhotoMetadata::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getNavigationLabel(): string
    {
        return 'Photo Metadata';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }

    public static function form(Schema $schema): Schema
    {
        return PhotoMetadataForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhotoMetadataTable::configure($table);
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
            'index' => ListPhotoMetadata::route('/'),
            'create' => CreatePhotoMetadata::route('/create'),
            'edit' => EditPhotoMetadata::route('/{record}/edit'),
        ];
    }
}
