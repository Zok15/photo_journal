<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSeries;
use App\Models\Series;
use Illuminate\Http\Request;

class SeriesController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $series = Series::create($data);

        ProcessSeries::dispatch($series->id);

        return response()->json([
            'id' => $series->id,
            'status' => 'queued',
        ], 201);
    }
}
