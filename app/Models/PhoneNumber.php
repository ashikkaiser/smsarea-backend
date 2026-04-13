<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PhoneNumber extends Model
{
    protected $fillable = [
        'device_id',
        'imei_or_device_uid',
        'sim_slot',
        'phone_number',
        'carrier_name',
        'country_code',
        'region_code',
        'status',
        'purchase_date',
        'expiry_date',
        'last_renewed_at',
        'metadata',
    ];

    protected $casts = [
        'purchase_date' => 'datetime',
        'expiry_date' => 'datetime',
        'last_renewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Digits only, for comparing user-typed numbers to stored values. */
    public static function normalizeDialable(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return is_string($digits) ? $digits : '';
    }

    /**
     * True when stored and typed values refer to the same line (exact digits or same last 10 for NANP-style input).
     */
    public static function dialableMatches(string $storedRaw, string $input): bool
    {
        $stored = self::normalizeDialable($storedRaw);
        $want = self::normalizeDialable($input);
        if ($want === '' || $stored === '') {
            return false;
        }
        if ($stored === $want) {
            return true;
        }
        if (strlen($want) >= 10 && strlen($stored) >= 10 && substr($want, -10) === substr($stored, -10)) {
            return true;
        }

        return false;
    }

    /**
     * Match a phone number row by human input (formatted or raw digits).
     */
    public static function findByDialableInput(string $input): ?self
    {
        $want = self::normalizeDialable($input);
        if ($want === '') {
            return null;
        }

        return self::query()
            ->whereNotNull('phone_number')
            ->get()
            ->first(static function (self $row) use ($input): bool {
                return self::dialableMatches((string) $row->phone_number, $input);
            });
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(PhoneNumberPurchase::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PhoneNumberOrder::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'phone_number_user')
            ->withPivot(['id', 'assigned_by', 'assigned_at', 'unassigned_at', 'status'])
            ->withTimestamps();
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_phone_number')
            ->withPivot(['id', 'assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * Rows that will be removed or unlinked when this number is deleted (DB cascades + pivot cleanup).
     *
     * @return array{
     *     active_user_assignments:int,
     *     inactive_user_assignments:int,
     *     campaign_links:int,
     *     conversations:int,
     *     messages:int,
     *     purchases:int,
     *     linked_device:bool,
     *     device_uid:string|null,
     * }
     */
    public function deleteImpactCounts(): array
    {
        $activeAssignments = (int) $this->users()
            ->wherePivot('status', 'active')
            ->count();

        $inactiveAssignments = (int) DB::table('phone_number_user')
            ->where('phone_number_id', $this->id)
            ->where('status', '!=', 'active')
            ->count();

        return [
            'active_user_assignments' => $activeAssignments,
            'inactive_user_assignments' => $inactiveAssignments,
            'campaign_links' => (int) DB::table('campaign_phone_number')
                ->where('phone_number_id', $this->id)
                ->count(),
            'conversations' => (int) DB::table('conversations')
                ->where('phone_number_id', $this->id)
                ->count(),
            'messages' => (int) DB::table('messages')
                ->where('phone_number_id', $this->id)
                ->count(),
            'purchases' => $this->purchases()->count(),
            'linked_device' => $this->device_id !== null,
            'device_uid' => $this->relationLoaded('device')
                ? $this->device?->device_uid
                : (optional($this->device()->first())->device_uid),
        ];
    }
}
