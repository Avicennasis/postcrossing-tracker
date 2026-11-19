Your postcard has arrived!

Hi {!! $ownerName ?: 'there' !!}, {!! $recipient ?: 'your recipient' !!} just registered it.

Postcard:        {!! $cardLabel !!}
Arrived in:      {!! $country !!}
Sent from:       {!! $sentFrom !!}
@if ($daysInTransit !== null)
Days in transit: {!! $daysInTransit !!}
@endif
@if ($distanceKm !== null)
Distance:        ~{!! \App\Support\Money::number($distanceKm) !!} km
@endif
@if (filled($senderMessage))

Message from {!! $recipient ?: 'the recipient' !!}:
{!! $senderMessage !!}
@endif

View it: {!! $viewUrl !!}

Sent by {!! config('app.name', 'Postcard Tracker') !!}. Every postcard tells a story.
@if (! empty($provenance))
--
@foreach ($provenance as $line)
{!! $line !!}
@endforeach
@endif
