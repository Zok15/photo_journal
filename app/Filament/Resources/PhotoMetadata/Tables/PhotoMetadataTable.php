<?php

namespace App\Filament\Resources\PhotoMetadata\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhotoMetadataTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('photo_id')
                    ->sortable(),
                TextColumn::make('photo.original_name')
                    ->label('Photo')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('taken_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('camera_make')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('camera_model')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('lens_model')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('iso')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('exposure_time')
                    ->toggleable(),
                TextColumn::make('aperture')
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('focal_length_mm')
                    ->label('Focal length')
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('width')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('height')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('orientation')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('latitude')
                    ->numeric(7)
                    ->toggleable(),
                TextColumn::make('longitude')
                    ->numeric(7)
                    ->toggleable(),
                TextColumn::make('altitude_m')
                    ->numeric(2)
                    ->toggleable(),
                TextColumn::make('flash_fired')
                    ->formatStateUsing(static function ($state): string {
                        if ($state === null) {
                            return 'n/a';
                        }

                        return (bool) $state ? 'yes' : 'no';
                    })
                    ->toggleable(),
                TextColumn::make('white_balance_mode')
                    ->toggleable(),
                TextColumn::make('color_space')
                    ->toggleable(),
                TextColumn::make('source_file_size')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
