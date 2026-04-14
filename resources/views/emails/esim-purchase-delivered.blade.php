<p>Hello {{ $userEsim->user->name }},</p>
<p>Your eSIM purchase is complete. You can also view these details in your dashboard.</p>
<ul>
    <li>Phone Number: {{ $userEsim->esim->phone_number }}</li>
    <li>ICCID: {{ $userEsim->esim->iccid }}</li>
    <li>Manual Code: {{ $userEsim->esim->manual_code ?? 'N/A' }}</li>
    <li>ZIP Code: {{ $userEsim->esim->zip_code ?? 'N/A' }}</li>
    <li>Area Code: {{ $userEsim->esim->area_code ?? 'N/A' }}</li>
</ul>
@if($userEsim->esim->qr_code)
    <p>QR Code / URL:</p>
    <p>{{ $userEsim->esim->qr_code }}</p>
@endif
<p>Thanks.</p>
