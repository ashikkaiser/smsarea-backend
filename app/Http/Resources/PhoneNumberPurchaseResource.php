<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhoneNumberPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'user_id' => $this->user_id,
            'purchase_date' => $this->purchase_date,
            'expiry_date' => $this->expiry_date,
            'status' => $this->status,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'phone_number' => new PhoneNumberResource($this->whenLoaded('phoneNumber')),
        ];
    }
}
