<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportCsvRequest;
use App\Services\ManaBoxImportService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    public function __construct(private readonly ManaBoxImportService $importer) {}

    public function store(ImportCsvRequest $request): JsonResponse
    {
        $result = $this->importer->import(
            file:       $request->file('csv_file'),
            user:       $request->user(),
            locationId: $request->integer('location_id') ?: null,
        );

        return response()->json($result);
    }
}
