<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\CampaignStatusUpdateRequest;
use App\Http\Requests\Campaign\CampaignStepStoreRequest;
use App\Http\Requests\Campaign\CampaignStoreRequest;
use App\Http\Requests\Campaign\CampaignUpdateRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignBuilderLimit;
use App\Models\PhoneNumber;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CampaignService $campaignService) {}

    public function builderLimits(): JsonResponse
    {
        $row = CampaignBuilderLimit::current();

        return $this->success([
            'max_reply_steps' => (int) $row->max_reply_steps,
            'max_followup_steps' => (int) $row->max_followup_steps,
        ], 'Campaign builder limits fetched.');
    }

    public function index(): JsonResponse
    {
        $campaigns = Campaign::query()
            ->where('user_id', request()->user()->id)
            ->with(['steps', 'phoneNumbers'])
            ->latest()
            ->get();

        return $this->success(CampaignResource::collection($campaigns), 'Campaigns fetched.');
    }

    /**
     * Phone lines the user may attach to campaigns (active assignments).
     *
     * Query: for_campaign_id — when editing that campaign, omit locks for numbers already on it so the UI can show them as selectable.
     */
    public function assignablePhoneNumbers(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = $user
            ->assignedPhoneNumbers()
            ->wherePivot('status', 'active')
            ->orderBy('phone_numbers.phone_number')
            ->get(['phone_numbers.id', 'phone_numbers.phone_number', 'phone_numbers.carrier_name', 'phone_numbers.sim_slot']);

        $ids = $rows->pluck('id')->all();
        if ($ids === []) {
            return $this->success([], 'Assignable phone numbers fetched.');
        }

        $excludeCampaignId = null;
        $raw = $request->query('for_campaign_id');
        if ($raw !== null && $raw !== '' && ctype_digit((string) $raw)) {
            $cid = (int) $raw;
            if (Campaign::query()->where('id', $cid)->where('user_id', $user->id)->exists()) {
                $excludeCampaignId = $cid;
            }
        }

        $lockQuery = DB::table('campaign_phone_number')
            ->whereIn('phone_number_id', $ids)
            ->join('campaigns', 'campaigns.id', '=', 'campaign_phone_number.campaign_id')
            ->select(['phone_number_id', 'campaigns.id as campaign_id', 'campaigns.name as campaign_name']);

        if ($excludeCampaignId !== null) {
            $lockQuery->where('campaign_phone_number.campaign_id', '!=', $excludeCampaignId);
        }

        $locks = $lockQuery->get()->keyBy('phone_number_id');

        $payload = $rows->map(static function ($pn) use ($locks) {
            $lock = $locks->get($pn->id);

            return [
                'id' => $pn->id,
                'phone_number' => $pn->phone_number,
                'carrier_name' => $pn->carrier_name,
                'sim_slot' => $pn->sim_slot,
                'locked_by_campaign_id' => $lock?->campaign_id,
                'locked_by_campaign_name' => $lock?->campaign_name,
            ];
        })->values()->all();

        return $this->success($payload, 'Assignable phone numbers fetched.');
    }

    public function store(CampaignStoreRequest $request): JsonResponse
    {
        $campaign = $this->campaignService->createCampaign($request->user(), $request->validated());

        return $this->success(new CampaignResource($campaign->load(['steps', 'phoneNumbers'])), 'Campaign created.', 201);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        $this->authorize('view', $campaign);

        return $this->success(new CampaignResource($campaign->load(['steps', 'phoneNumbers'])), 'Campaign fetched.');
    }

    public function update(CampaignUpdateRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $updated = $this->campaignService->updateCampaign($campaign, $request->validated(), $request->user());

        return $this->success(new CampaignResource($updated->load(['steps', 'phoneNumbers'])), 'Campaign updated.');
    }

    public function updateStatus(CampaignStatusUpdateRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $updated = $this->campaignService->updateStatus($campaign, $request->validated('status'));

        return $this->success(new CampaignResource($updated->load(['steps', 'phoneNumbers'])), 'Campaign status updated.');
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        $this->authorize('delete', $campaign);
        $campaign->delete();

        return $this->success(null, 'Campaign deleted.');
    }

    public function addStep(CampaignStepStoreRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('update', $campaign);
        $this->campaignService->addStep($campaign, $request->validated());

        return $this->success(null, 'Campaign step created.', 201);
    }

    public function assignNumber(Campaign $campaign, PhoneNumber $phoneNumber): JsonResponse
    {
        $this->authorize('assignNumber', $campaign);
        $this->campaignService->attachPhoneNumberToCampaign(request()->user(), $campaign, $phoneNumber);

        return $this->success(null, 'Phone number assigned to campaign.');
    }
}
