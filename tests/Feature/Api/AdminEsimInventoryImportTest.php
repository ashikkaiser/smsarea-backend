<?php

namespace Tests\Feature\Api;

use App\Models\EsimCarrierPlan;
use App\Models\EsimInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminEsimInventoryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_maps_supplier_headers_and_default_carrier_slug(): void
    {
        $plan = EsimCarrierPlan::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $csv = <<<'CSV'
ICCID,QRActivationCode,Zip Code,Phone Number
8901240527139843380,LPA:1$T-MOBILE.IDEMIA.IO$ABC,29407,8434713855
CSV;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/admin/esims/import', [
                'csv' => UploadedFile::fake()->createWithContent('bulk.csv', $csv),
                'default_carrier_slug' => $plan->slug,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.imported', 1);
        $response->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('esim_inventories', [
            'iccid' => '8901240527139843380',
            'phone_number' => '8434713855',
            'zip_code' => '29407',
            'area_code' => '843',
            'esim_carrier_plan_id' => $plan->id,
        ]);

        $row = EsimInventory::query()->where('iccid', '8901240527139843380')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('LPA:1$', (string) $row->qr_code);
    }

    public function test_csv_import_derives_area_code_from_phone_not_csv_column(): void
    {
        $plan = EsimCarrierPlan::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $csv = <<<CSV
iccid,phone_number,area_code,carrier_slug
9998887776665554444,8434713855,999,{$plan->slug}
CSV;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/admin/esims/import', [
                'csv' => UploadedFile::fake()->createWithContent('bulk.csv', $csv),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.imported', 1);
        $this->assertDatabaseHas('esim_inventories', [
            'iccid' => '9998887776665554444',
            'area_code' => '843',
        ]);
        $this->assertDatabaseMissing('esim_inventories', [
            'iccid' => '9998887776665554444',
            'area_code' => '999',
        ]);
    }

    public function test_csv_import_requires_carrier_when_no_default(): void
    {
        EsimCarrierPlan::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $csv = "iccid,phone_number\n123,5551234567\n";

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/admin/esims/import', [
                'csv' => UploadedFile::fake()->createWithContent('bulk.csv', $csv),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.imported', 0);
        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('carrier_slug', $errors[0]['error']);
    }

    public function test_xlsx_import_reads_active_sheet(): void
    {
        $plan = EsimCarrierPlan::query()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('admin', ['user'])->plainTextToken;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['iccid', 'phone_number', 'carrier_slug', 'zip_code']], null, 'A1');
        $sheet->getCell('A2')->setValueExplicit('8901240527139843399', DataType::TYPE_STRING);
        $sheet->setCellValue('B2', '8434713855');
        $sheet->setCellValue('C2', $plan->slug);
        $sheet->setCellValue('D2', '29407');

        $path = sys_get_temp_dir().'/'.uniqid('esim_xlsx_', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $uploaded = new UploadedFile(
                $path,
                'bulk.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true
            );
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->post('/api/v1/admin/esims/import', [
                    'csv' => $uploaded,
                ]);
        } finally {
            @unlink($path);
        }

        $response->assertOk();
        $response->assertJsonPath('data.imported', 1);
        $response->assertJsonPath('data.errors', []);

        $this->assertDatabaseHas('esim_inventories', [
            'iccid' => '8901240527139843399',
            'phone_number' => '8434713855',
            'zip_code' => '29407',
            'area_code' => '843',
            'esim_carrier_plan_id' => $plan->id,
        ]);
    }
}
