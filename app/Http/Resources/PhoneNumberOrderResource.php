<?php

namespace App\Http\Resources;

use App\Models\PhoneNumberOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PhoneNumberOrder */
class PhoneNumberOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'duration_days' => $this->duration_days,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'source' => $this->source,
            'provider_payment_id' => $this->provider_payment_id,
            'provider_pay_address' => $this->provider_pay_address,
            'provider_pay_currency' => $this->provider_pay_currency,
            'provider_pay_amount' => $this->provider_pay_amount,
            'phone_number_purchase_id' => $this->phone_number_purchase_id,
            'created_at' => $this->created_at,
            'phone_number' => $this->whenLoaded('phoneNumber', function () {
                return new PhoneNumberCatalogResource($this->phoneNumber);
            }),
        ];
    }
}
