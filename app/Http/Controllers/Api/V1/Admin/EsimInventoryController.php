<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EsimInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EsimInventoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rows = EsimInventory::query()->latest('id')->paginate(50);

        return $this->success($rows, 'eSIM inventory fetched.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'iccid' => ['required', 'string', 'max:64'],
            'phone_number' => ['required', 'string', 'max:32'],
            'qr_code' => ['nullable', 'string'],
            'manual_code' => ['nullable', 'string', 'max:128'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'area_code' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'in:available,reserved,sold'],
        ]);

        $row = EsimInventory::query()->create($data);

        return $this->success($row, 'eSIM created.', 201);
    }

    public function update(Request $request, EsimInventory $esim): JsonResponse
    {
        $data = $request->validate([
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'qr_code' => ['sometimes', 'nullable', 'string'],
            'manual_code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'area_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'string', 'in:available,reserved,sold'],
        ]);

        $esim->update($data);

        return $this->success($esim->fresh(), 'eSIM updated.');
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $data['csv'];
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            return $this->failure('Unable to open CSV file.', 422);
        }

        $header = null;
        $imported = 0;
        $errors = [];
        $line = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if ($line === 1) {
                $header = array_map(static fn ($v) => strtolower(trim((string) $v)), $row);
                continue;
            }
            if (! is_array($header)) {
                continue;
            }
            $assoc = [];
            foreach ($header as $idx => $key) {
                $assoc[$key] = $row[$idx] ?? null;
            }

            try {
                EsimInventory::query()->updateOrCreate(
                    ['iccid' => (string) ($assoc['iccid'] ?? '')],
                    [
                        'phone_number' => (string) ($assoc['phone_number'] ?? ''),
                        'qr_code' => $assoc['qr_code'] ?? null,
                        'manual_code' => $assoc['manual_code'] ?? null,
                        'zip_code' => $assoc['zip_code'] ?? null,
                        'area_code' => $assoc['area_code'] ?? null,
                        'status' => in_array(($assoc['status'] ?? 'available'), ['available', 'reserved', 'sold'], true)
                            ? $assoc['status']
                            : 'available',
                    ]
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'error' => $e->getMessage()];
            }
        }
        fclose($handle);

        return $this->success([
            'imported' => $imported,
            'errors' => $errors,
        ], 'eSIM CSV import finished.');
    }
}
