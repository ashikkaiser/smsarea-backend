<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhoneNumberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'sim_slot' => $this->sim_slot,
            'phone_number' => $this->phone_number,
            'carrier_name' => $this->carrier_name,
            'status' => $this->status,
            'purchase_date' => $this->purchase_date,
            'expiry_date' => $this->expiry_date,
        ];
    }
}
