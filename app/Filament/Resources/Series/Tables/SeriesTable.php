<?php

namespace App\Filament\Resources\Series\Tables;

use App\Models\Series;
use App\Support\SeriesResponseCache;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SeriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Author')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('photos_count')
                    ->counts('photos')
                    ->label('Photos')
                    ->sortable(),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),
                TextColumn::make('publication_status')
                    ->label('Publication')
                    ->badge(),
                TextColumn::make('moderation_status')
                    ->label('Moderation')
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('publication_status')
                    ->options([
                        Series::PUBLICATION_DRAFT => 'draft',
                        Series::PUBLICATION_PENDING_MODERATION => 'pending_moderation',
                        Series::PUBLICATION_PUBLISHED => 'published',
                        Series::PUBLICATION_REJECTED => 'rejected',
                    ]),
            ])
            ->recordActions([
                Action::make('publishWithoutChecks')
                    ->label('Publish without checks')
                    ->requiresConfirmation()
                    ->visible(fn (Series $record): bool => (string) $record->publication_status === Series::PUBLICATION_PENDING_MODERATION)
                    ->action(function (Series $record): void {
                        $record->forceFill([
                            'is_public' => true,
                            'publication_status' => Series::PUBLICATION_PUBLISHED,
                            'moderation_status' => Series::MODERATION_MANUAL_APPROVED,
                            'moderation_reason' => 'Manually approved by admin.',
                            'moderation_labels' => [],
                            'moderated_at' => now(),
                            'moderated_by' => auth()->id(),
                        ])->save();
                        SeriesResponseCache::bumpUser((int) $record->user_id);
                        SeriesResponseCache::bumpSeries((int) $record->id);
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
