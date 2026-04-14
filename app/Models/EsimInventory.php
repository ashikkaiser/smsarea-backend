<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EsimInventory extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SOLD = 'sold';

    protected $fillable = [
        'iccid',
        'phone_number',
        'qr_code',
        'manual_code',
        'zip_code',
        'area_code',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function userEsim(): HasOne
    {
        return $this->hasOne(UserEsim::class);
    }

    public function maskedPhoneNumber(): string
    {
        $raw = (string) $this->phone_number;
        $len = strlen($raw);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($raw, 0, 2).str_repeat('*', max(0, $len - 4)).substr($raw, -2);
    }
}
