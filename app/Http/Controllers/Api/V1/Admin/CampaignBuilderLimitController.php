<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCampaignBuilderLimitsRequest;
use App\Models\CampaignBuilderLimit;
use Illuminate\Http\JsonResponse;

class CampaignBuilderLimitController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        $row = CampaignBuilderLimit::current();

        return $this->success([
            'max_reply_steps' => (int) $row->max_reply_steps,
            'max_followup_steps' => (int) $row->max_followup_steps,
        ], 'Campaign builder limits fetched.');
    }

    public function update(UpdateCampaignBuilderLimitsRequest $request): JsonResponse
    {
        $row = CampaignBuilderLimit::current();
        $row->update($request->validated());

        return $this->success([
            'max_reply_steps' => (int) $row->fresh()->max_reply_steps,
            'max_followup_steps' => (int) $row->fresh()->max_followup_steps,
        ], 'Campaign builder limits updated.');
    }
}
