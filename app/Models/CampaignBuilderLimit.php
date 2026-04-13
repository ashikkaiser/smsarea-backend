<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignBuilderLimit extends Model
{
    protected $table = 'campaign_builder_limits';

    protected $fillable = [
        'max_reply_steps',
        'max_followup_steps',
    ];

    public static function current(): self
    {
        /** @var self|null $row */
        $row = self::query()->orderBy('id')->first();

        if ($row === null) {
            $row = self::query()->create([
                'max_reply_steps' => 10,
                'max_followup_steps' => 10,
            ]);
        }

        return $row;
    }
}
