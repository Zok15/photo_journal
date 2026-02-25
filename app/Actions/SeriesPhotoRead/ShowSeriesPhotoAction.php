<?php

namespace App\Actions\SeriesPhotoRead;

use App\Models\Photo;

class ShowSeriesPhotoAction
{
    /**
     * @return array{data:Photo}
     */
    public function execute(Photo $photo): array
    {
        $photo->loadMissing('metadata');

        return [
            'data' => $photo,
        ];
    }
}
