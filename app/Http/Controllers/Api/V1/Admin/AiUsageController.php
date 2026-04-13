<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\Campaign;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiUsageController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 30);
        $perPage = max(5, min($perPage, 100));

        $from = $this->parseDateStart($request->query('from'));
        $to = $this->parseDateEnd($request->query('to'));

        $tokenExpr = 'COALESCE(total_tokens, COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0))';

        $table = $this->filteredTable($from, $to);

        $summary = [
            'request_count' => (clone $table)->count(),
            'sum_prompt_tokens' => (int) (clone $table)->sum('prompt_tokens'),
            'sum_completion_tokens' => (int) (clone $table)->sum('completion_tokens'),
            'sum_total_tokens_column' => (int) (clone $table)->sum('total_tokens'),
            'sum_tokens_approx' => (int) (clone $table)
                ->selectRaw("COALESCE(SUM({$tokenExpr}), 0) as s")
                ->value('s'),
        ];

        $byUserRows = (clone $table)
            ->selectRaw('user_id, COUNT(*) as request_count, SUM(COALESCE(prompt_tokens, 0)) as prompt_sum, SUM(COALESCE(completion_tokens, 0)) as completion_sum, SUM('.$tokenExpr.') as token_sum')
            ->groupBy('user_id')
            ->orderByDesc('token_sum')
            ->limit(100)
            ->get();

        $userIds = $byUserRows->pluck('user_id')->all();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        $byUser = $byUserRows->map(function ($row) use ($users) {
            $u = $users->get($row->user_id);

            return [
                'user_id' => (int) $row->user_id,
                'request_count' => (int) $row->request_count,
                'prompt_sum' => (int) $row->prompt_sum,
                'completion_sum' => (int) $row->completion_sum,
                'token_sum' => (int) $row->token_sum,
                'user' => $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email] : null,
            ];
        })->values()->all();

        $byCampaignRows = (clone $table)
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, COUNT(*) as request_count, SUM(COALESCE(prompt_tokens, 0)) as prompt_sum, SUM(COALESCE(completion_tokens, 0)) as completion_sum, SUM('.$tokenExpr.') as token_sum')
            ->groupBy('campaign_id')
            ->orderByDesc('token_sum')
            ->limit(100)
            ->get();

        $campaignIds = $byCampaignRows->pluck('campaign_id')->all();
        $campaigns = Campaign::query()->whereIn('id', $campaignIds)->get(['id', 'name', 'user_id'])->keyBy('id');

        $byCampaign = $byCampaignRows->map(function ($row) use ($campaigns) {
            $c = $campaigns->get($row->campaign_id);

            return [
                'campaign_id' => (int) $row->campaign_id,
                'request_count' => (int) $row->request_count,
                'prompt_sum' => (int) $row->prompt_sum,
                'completion_sum' => (int) $row->completion_sum,
                'token_sum' => (int) $row->token_sum,
                'campaign' => $c ? ['id' => $c->id, 'name' => $c->name, 'user_id' => $c->user_id] : null,
            ];
        })->values()->all();

        $logs = AiUsageLog::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->with(['user:id,name,email', 'campaign:id,name,user_id'])
            ->latest('id')
            ->paginate($perPage);

        $logs->through(function (AiUsageLog $log) {
            return [
                'id' => $log->id,
                'source' => $log->source,
                'model' => $log->model,
                'prompt_tokens' => $log->prompt_tokens,
                'completion_tokens' => $log->completion_tokens,
                'total_tokens' => $log->total_tokens,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name, 'email' => $log->user->email] : null,
                'campaign' => $log->campaign ? ['id' => $log->campaign->id, 'name' => $log->campaign->name] : null,
            ];
        });

        return $this->success([
            'range' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            'summary' => $summary,
            'by_user' => $byUser,
            'by_campaign' => $byCampaign,
            'logs' => $logs,
        ]);
    }

    private function filteredTable(?Carbon $from, ?Carbon $to): Builder
    {
        $q = DB::table('ai_usage_logs');
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<=', $to);
        }

        return $q;
    }

    private function parseDateStart(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateEnd(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
