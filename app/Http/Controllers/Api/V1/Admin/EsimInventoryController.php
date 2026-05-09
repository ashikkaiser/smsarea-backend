<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EsimCarrierPlan;
use App\Models\EsimInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EsimInventoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rows = EsimInventory::query()->with('carrierPlan')->latest('id')->paginate(50);

        return $this->success($rows, 'eSIM inventory fetched.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'esim_carrier_plan_id' => ['required', 'integer', 'exists:esim_carrier_plans,id'],
            'iccid' => ['required', 'string', 'max:64'],
            'phone_number' => ['required', 'string', 'max:32'],
            'qr_code' => ['nullable', 'string'],
            'manual_code' => ['nullable', 'string', 'max:128'],
            'zip_code' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'string', 'in:available,reserved,sold'],
        ]);

        $plan = EsimCarrierPlan::query()->findOrFail((int) $data['esim_carrier_plan_id']);
        if (! $plan->is_active) {
            return $this->failure('Selected carrier plan is not active.', 422);
        }

        $data['area_code'] = self::inferNanpAreaCodeFromPhone((string) $data['phone_number']);

        $row = EsimInventory::query()->create($data);

        $row->load('carrierPlan');

        return $this->success($row, 'eSIM created.', 201);
    }

    public function update(Request $request, EsimInventory $esim): JsonResponse
    {
        $data = $request->validate([
            'esim_carrier_plan_id' => ['sometimes', 'integer', 'exists:esim_carrier_plans,id'],
            'phone_number' => ['sometimes', 'string', 'max:32'],
            'qr_code' => ['sometimes', 'nullable', 'string'],
            'manual_code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'string', 'in:available,reserved,sold'],
        ]);

        if (isset($data['esim_carrier_plan_id'])) {
            $plan = EsimCarrierPlan::query()->findOrFail((int) $data['esim_carrier_plan_id']);
            if (! $plan->is_active) {
                return $this->failure('Selected carrier plan is not active.', 422);
            }
        }

        $phone = (string) ($data['phone_number'] ?? $esim->phone_number);
        $data['area_code'] = self::inferNanpAreaCodeFromPhone($phone);

        $esim->update($data);

        return $this->success($esim->fresh('carrierPlan'), 'eSIM updated.');
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
            'default_carrier_slug' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $defaultSlug = strtolower(trim((string) ($data['default_carrier_slug'] ?? '')));

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
                $header = array_map(static fn ($v) => self::normalizeImportHeaderCell((string) $v), $row);

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
                $slug = isset($assoc['carrier_slug']) ? strtolower(trim((string) $assoc['carrier_slug'])) : '';
                if ($slug === '') {
                    $slug = $defaultSlug;
                }
                if ($slug === '') {
                    throw new \InvalidArgumentException(
                        'carrier_slug is required per row, or pass default_carrier_slug with the upload (e.g. tmobile).'
                    );
                }
                $plan = EsimCarrierPlan::query()->where('slug', $slug)->first();
                if (! $plan) {
                    throw new \InvalidArgumentException('Unknown carrier_slug: '.$slug);
                }

                $phone = (string) ($assoc['phone_number'] ?? '');
                $areaCode = self::inferNanpAreaCodeFromPhone($phone);

                EsimInventory::query()->updateOrCreate(
                    ['iccid' => (string) ($assoc['iccid'] ?? '')],
                    [
                        'esim_carrier_plan_id' => $plan->id,
                        'phone_number' => $phone,
                        'qr_code' => isset($assoc['qr_code']) ? trim((string) $assoc['qr_code']) : null,
                        'manual_code' => isset($assoc['manual_code']) ? trim((string) $assoc['manual_code']) : null,
                        'zip_code' => isset($assoc['zip_code']) ? trim((string) $assoc['zip_code']) : null,
                        'area_code' => $areaCode,
                        'status' => self::normalizeImportStatus($assoc),
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

    /**
     * Maps supplier spreadsheet headers (Excel → Save as CSV) to internal snake_case keys.
     */
    private static function normalizeImportHeaderCell(string $raw): string
    {
        $k = strtolower(trim($raw));
        $k = str_replace([' ', '-'], '_', $k);
        $k = (string) preg_replace('/_+/', '_', $k);

        return match ($k) {
            'qractivationcode', 'qr_activation_code' => 'qr_code',
            'zipcode', 'zip' => 'zip_code',
            'phonenumber', 'phone', 'mobile', 'msisdn' => 'phone_number',
            'carrier', 'network', 'mvno' => 'carrier_slug',
            'manualcode' => 'manual_code',
            default => $k,
        };
    }

    /** NANP: 10-digit national or +1 / 1 prefix → area code (first 3 digits). */
    private static function inferNanpAreaCodeFromPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1, 3);
        }
        if (strlen($digits) >= 10) {
            return substr($digits, -10, 3);
        }

        return null;
    }

    /**
     * @param  array<string, mixed|null>  $assoc
     */
    private static function normalizeImportStatus(array $assoc): string
    {
        $raw = strtolower(trim((string) ($assoc['status'] ?? 'available')));

        return in_array($raw, ['available', 'reserved', 'sold'], true) ? $raw : 'available';
    }
}
