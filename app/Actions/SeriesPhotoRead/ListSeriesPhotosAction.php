<?php

namespace App\Actions\SeriesPhotoRead;

use App\Models\Series;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSeriesPhotosAction
{
    /**
     * @param array<string, mixed> $validated
     */
    public function execute(Series $series, array $validated): LengthAwarePaginator
    {
        $perPage = $validated['per_page'] ?? 15;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        return $series->photos()
            ->orderBy($sortBy, $sortDir)
            ->when($sortBy !== 'id', function ($query) use ($sortDir): void {
                $query->orderBy('id', $sortDir);
            })
            ->paginate($perPage)
            ->withQueryString();
    }
}
