<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\EsimCarrierPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EsimCarrierPlanController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $rows = EsimCarrierPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (EsimCarrierPlan $p): array => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'price_minor' => (int) $p->price_minor,
                'duration_days' => $p->duration_days,
                'sort_order' => (int) $p->sort_order,
                'is_active' => (bool) $p->is_active,
            ]);

        return $this->success($rows, 'eSIM carrier plans fetched.');
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.id' => ['nullable', 'integer', 'exists:esim_carrier_plans,id'],
            'plans.*.slug' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'plans.*.name' => ['required', 'string', 'max:128'],
            'plans.*.price_minor' => ['required', 'integer', 'min:0'],
            'plans.*.duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'plans.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'plans.*.is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data): void {
            $keptIds = [];

            foreach ($data['plans'] as $item) {
                $payload = [
                    'slug' => (string) $item['slug'],
                    'name' => (string) $item['name'],
                    'price_minor' => (int) $item['price_minor'],
                    'duration_days' => isset($item['duration_days']) ? (int) $item['duration_days'] : null,
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'is_active' => (bool) ($item['is_active'] ?? true),
                ];

                if (! empty($item['id'])) {
                    $plan = EsimCarrierPlan::query()->lockForUpdate()->findOrFail((int) $item['id']);
                    if ($plan->slug !== $payload['slug']) {
                        $exists = EsimCarrierPlan::query()
                            ->where('slug', $payload['slug'])
                            ->whereKeyNot($plan->id)
                            ->exists();
                        if ($exists) {
                            throw new RuntimeException('Duplicate carrier slug: '.$payload['slug']);
                        }
                    }
                    $plan->update($payload);
                    $keptIds[] = (int) $plan->id;
                } else {
                    $exists = EsimCarrierPlan::query()->where('slug', $payload['slug'])->exists();
                    if ($exists) {
                        throw new RuntimeException('Duplicate carrier slug: '.$payload['slug']);
                    }
                    $created = EsimCarrierPlan::query()->create($payload);
                    $keptIds[] = (int) $created->id;
                }
            }

            EsimCarrierPlan::query()
                ->whereNotIn('id', $keptIds)
                ->delete();
        });

        return $this->index();
    }
}
