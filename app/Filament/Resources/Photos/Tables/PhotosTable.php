<?php

namespace App\Filament\Resources\Photos\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhotosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('series.title')
                    ->label('Series')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('path')
                    ->label('Original Path')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('preview_path')
                    ->label('Preview Path')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('mime')
                    ->searchable(),
                TextColumn::make('size')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
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
