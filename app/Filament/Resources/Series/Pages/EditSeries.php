<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SeriesResource;
use App\Models\Series;
use App\Support\SeriesResponseCache;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSeries extends EditRecord
{
    protected static string $resource = SeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publishWithoutChecks')
                ->label('Publish without checks')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $record = $this->getRecord();

                    return in_array((string) $record->publication_status, [
                        Series::PUBLICATION_PENDING_MODERATION,
                        Series::PUBLICATION_REJECTED,
                    ], true);
                })
                ->action(function (): void {
                    /** @var Series $record */
                    $record = $this->getRecord();
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
                    $this->refreshFormData(['is_public', 'publication_status', 'moderation_status']);
                }),
            DeleteAction::make(),
        ];
    }
}
