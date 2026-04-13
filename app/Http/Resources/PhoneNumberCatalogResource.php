<?php

namespace App\Http\Resources;

use App\Models\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhoneNumber */
class PhoneNumberCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            'carrier_name' => $this->carrier_name,
            'country_code' => $this->country_code,
            'region_code' => $this->region_code,
            'status' => $this->status,
        ];
    }
}
