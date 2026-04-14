<?php

namespace App\Mail;

use App\Models\UserEsim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EsimPurchaseDeliveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public UserEsim $userEsim) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your eSIM details are ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.esim-purchase-delivered',
            with: ['userEsim' => $this->userEsim],
        );
    }
}
