<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Postcard arrived</title>
</head>
<body style="margin:0; padding:0; background-color:#efe6d2;">
{{-- Postcard-themed arrival alert: tables + inline CSS for email clients; mobile via fluid widths. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ $cardLabel }} reached {{ $country }}{{ $daysInTransit !== null ? ' after '.$daysInTransit.' days' : '' }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#efe6d2;">
<tr><td align="center" style="padding:24px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background-color:#fffaf0; border:1px solid #d9cbb0; border-radius:6px;">
    {{-- Airmail edge --}}
    <tr><td style="padding:0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            @for ($i = 0; $i < 14; $i++)
                <td height="8" style="height:8px; font-size:0; line-height:0; background-color:{{ $i % 2 ? '#457b9d' : '#e63946' }};">&nbsp;</td>
            @endfor
        </tr></table>
    </td></tr>

    <tr><td style="padding:24px 24px 8px 24px; font-family:Georgia, 'Times New Roman', serif; color:#2b2d42;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="top" style="font-family:Georgia, 'Times New Roman', serif;">
                <div style="font-size:12px; letter-spacing:3px; text-transform:uppercase; color:#e63946; font-family:'Courier New', Courier, monospace;">Par avion &middot; Arrival confirmed</div>
                <div style="font-size:26px; line-height:1.25; margin-top:6px; color:#2b2d42;">Your postcard has arrived!</div>
                <div style="font-size:15px; color:#5c5f73; margin-top:6px;">Hi {{ $ownerName ?: 'there' }}, {{ $recipient ?: 'your recipient' }} just registered it.</div>
            </td>
            {{-- Stamp --}}
            <td valign="top" align="right" width="96" style="width:96px; padding-left:12px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border:3px dotted #c9b48a; background-color:#ffffff;"><tr>
                    <td align="center" style="width:78px; padding:8px 4px; font-family:'Courier New', Courier, monospace; color:#457b9d;">
                        <div style="font-size:22px; line-height:1;">&#9993;</div>
                        <div style="font-size:10px; letter-spacing:1px; margin-top:4px; text-transform:uppercase;">{{ \Illuminate\Support\Str::limit($country, 14, '') }}</div>
                        <div style="font-size:9px; color:#e63946; margin-top:2px;">{{ now()->format('d M Y') }}</div>
                    </td>
                </tr></table>
            </td>
        </tr></table>
    </td></tr>

    @if ($imageCid)
    <tr><td style="padding:12px 24px 4px 24px;">
        <img src="{{ $imageCid }}" alt="Front of postcard {{ $cardLabel }}" width="512" style="display:block; width:100%; max-width:512px; height:auto; border:6px solid #ffffff; border-radius:2px; box-shadow:0 2px 6px rgba(0,0,0,0.15);">
    </td></tr>
    @endif

    {{-- Details, written like the back of a postcard --}}
    <tr><td style="padding:16px 24px 8px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:'Courier New', Courier, monospace; font-size:14px; color:#2b2d42; border-top:1px dashed #d9cbb0;">
            <tr><td style="padding:10px 0 4px 0; color:#8a7f6a; width:42%;">Postcard</td><td style="padding:10px 0 4px 0; font-weight:bold;">{{ $cardLabel }}</td></tr>
            <tr><td style="padding:4px 0; color:#8a7f6a;">Arrived in</td><td style="padding:4px 0; font-weight:bold;">{{ $country }}</td></tr>
            <tr><td style="padding:4px 0; color:#8a7f6a;">Sent from</td><td style="padding:4px 0;">{{ $sentFrom }}</td></tr>
            @if ($daysInTransit !== null)
            <tr><td style="padding:4px 0; color:#8a7f6a;">Days in transit</td><td style="padding:4px 0;">{{ $daysInTransit }} {{ $daysInTransit === 1 ? 'day' : 'days' }}</td></tr>
            @endif
            @if ($distanceKm !== null)
            <tr><td style="padding:4px 0 10px 0; color:#8a7f6a;">Distance</td><td style="padding:4px 0 10px 0;">~{{ \App\Support\Money::number($distanceKm) }} km</td></tr>
            @endif
        </table>
    </td></tr>

    @if (filled($senderMessage))
    <tr><td style="padding:4px 24px 8px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f6efe0; border-left:4px solid #457b9d;"><tr>
            <td style="padding:12px 14px; font-family:Georgia, 'Times New Roman', serif; font-size:15px; line-height:1.5; color:#2b2d42;">
                <div style="font-size:11px; letter-spacing:2px; text-transform:uppercase; color:#457b9d; font-family:'Courier New', Courier, monospace; margin-bottom:4px;">Message from {{ $recipient ?: 'the recipient' }}</div>
                {!! nl2br(e($senderMessage)) !!}
            </td>
        </tr></table>
    </td></tr>
    @endif

    <tr><td align="center" style="padding:16px 24px 24px 24px;">
        <a href="{{ $viewUrl }}" style="display:inline-block; background-color:#e63946; color:#ffffff; text-decoration:none; font-family:'Courier New', Courier, monospace; font-size:14px; letter-spacing:1px; padding:10px 18px; border-radius:3px;">VIEW THIS POSTCARD</a>
    </td></tr>

    <tr><td style="padding:0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            @for ($i = 0; $i < 14; $i++)
                <td height="8" style="height:8px; font-size:0; line-height:0; background-color:{{ $i % 2 ? '#e63946' : '#457b9d' }};">&nbsp;</td>
            @endfor
        </tr></table>
    </td></tr>
</table>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
    <tr><td style="padding:12px 8px 0 8px; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#8a7f6a; text-align:center;">
        Sent by {{ config('app.name', 'Postcard Tracker') }} &middot; Every postcard tells a story.
    </td></tr>
    @if (! empty($provenance))
    <tr><td style="padding:10px 8px 0 8px; font-family:'Courier New', Courier, monospace; font-size:11px; line-height:1.5; color:#9a8f7a; text-align:left;">
        --<br>
        @foreach ($provenance as $line){{ $line }}<br>@endforeach
    </td></tr>
    @endif
</table>

</td></tr>
</table>
</body>
</html>
