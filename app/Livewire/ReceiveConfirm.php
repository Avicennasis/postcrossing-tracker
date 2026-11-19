<?php

namespace App\Livewire;

use App\Models\Postcard;
use App\Traits\CalculatesDistance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

class ReceiveConfirm extends Component
{
    use CalculatesDistance;

    public $uid;

    public $postcard;

    public $message = '';

    public $step = 1;

    // Derived state
    public $alreadyConfirmed = false;

    public $justConfirmed = false;

    public $isSwap = false;

    public $isChina = false;

    public $distance = 0;

    // Map markers. This page is public, so the sender's marker uses rounded
    // coordinates (about 11 km); the exact origin never reaches the browser.
    public $myLat;

    public $myLng;

    public $originName;

    public $targetLat = 0;

    public $targetLng = 0;

    public function mount($uid)
    {
        $this->uid = $uid;
        $this->postcard = Postcard::with(['contact', 'country', 'origin'])->where('uid', $uid)->where('type', 'sent')->firstOrFail();

        $origin = $this->postcard->ownerPlace();
        ['lat' => $this->myLat, 'lng' => $this->myLng] = $origin->coarse();
        // Home is shown as OWNER_LOCATION (hidden when unset); a travel origin by its name
        $this->originName = $origin->isHome ? config('app.owner_location') : $origin->name;

        $this->alreadyConfirmed = ! empty($this->postcard->tanggal_terima) && $this->postcard->tanggal_terima != '0000-00-00';
        $this->isSwap = ! Str::contains($this->postcard->postcard_id, '-');

        // Detect China Viewer
        $this->detectChinaViewer();

        // China keyword check for destination (AMap is better for China addresses)
        $isToChina = false;
        $chinaKeywords = ['china', 'tiongkok', 'prc', 'people\'s republic of china'];
        $countryName = $this->postcard->country?->nama_indonesia ?? '';
        foreach ($chinaKeywords as $kw) {
            if (stripos($countryName, $kw) !== false) {
                $isToChina = true;
                break;
            }
        }

        if ($isToChina) {
            $this->isChina = true;
        }

        // Coordinates
        $this->targetLat = (float) ($this->postcard->contact?->lat ?? 0);
        $this->targetLng = (float) ($this->postcard->contact?->lng ?? 0);

        // Public page: coarse distance only (see Place::publicDistanceTo)
        if ($this->targetLat && $this->targetLng) {
            $this->distance = $origin->publicDistanceTo($this->targetLat, $this->targetLng);
        }
    }

    public function detectChinaViewer()
    {
        // URL Override
        if (request()->has('china')) {
            $this->isChina = (request()->query('china') == '1');

            return;
        }

        // Cloudflare IP Detection
        $cfCountry = request()->header('CF-IPCountry');
        if ($cfCountry === 'CN') {
            $this->isChina = true;

            return;
        }

        $this->isChina = false;
    }

    // calculateDistance() provided by CalculatesDistance trait

    public function confirm()
    {
        if ($this->alreadyConfirmed) {
            return;
        }

        $this->postcard->update([
            'tanggal_terima' => now()->toDateString(),
            'notif_read' => 0,
            'pesan_penerima' => $this->message,
        ]);

        $this->alreadyConfirmed = true;
        $this->justConfirmed = true;

        $this->notifyOwner();
    }

    /**
     * Email the owner that a card arrived. Recipient and sender come only
     * from OWNER_EMAIL and MAIL_FROM_*; when either is missing the email is
     * skipped with a warning instead of going to a hardcoded address.
     */
    protected function notifyOwner(): void
    {
        $ownerEmail = config('app.owner_email');
        $context = ['postcard_id' => $this->postcard->id];

        if (blank($ownerEmail)) {
            Log::warning('Arrival email not sent: OWNER_EMAIL is not set.', $context);

            return;
        }

        if (blank(config('mail.from.address'))) {
            Log::warning('Arrival email not sent: MAIL_FROM_ADDRESS is not set.', $context);

            return;
        }

        try {
            $card = $this->postcard->fresh(['contact', 'country', 'origin']);
            $subject = '📬 Postcard Arrived! ['.($card->country?->display_name ?? 'Unknown').']';
            $image = $this->cardImage($card);

            // The sender is the mailer's global "from" (MAIL_FROM_ADDRESS / MAIL_FROM_NAME).
            Mail::send(
                ['html' => 'emails.postcard-arrived', 'text' => 'emails.postcard-arrived-text'],
                $this->arrivalEmailData($card, $image),
                function ($message) use ($ownerEmail, $subject, $image) {
                    $message->to($ownerEmail)->subject($subject);
                    self::useBase64Body($message->getSymfonyMessage(), $image);
                }
            );
        } catch (\Throwable $e) {
            // The recipient's confirmation is already saved; don't fail their request.
            Log::error('Arrival email failed: '.$e->getMessage(), $context);
        }
    }

    /**
     * Template data. Like the public page, the email only carries the
     * rounded distance and no coordinates.
     *
     * @return array<string, mixed>
     */
    protected function arrivalEmailData(Postcard $card, ?DataPart $image): array
    {
        $origin = $card->ownerPlace();
        $lat = $card->contact?->lat;
        $lng = $card->contact?->lng;

        return [
            'ownerName' => config('app.owner_name'),
            'senderMessage' => $this->message,
            'cardLabel' => $card->postcard_id ?: 'Direct swap',
            'country' => $card->country?->display_name ?? 'Unknown',
            'recipient' => $card->contact?->nama_kontak,
            'sentFrom' => $origin->isHome ? (config('app.owner_location') ?: 'Home') : $origin->name,
            'daysInTransit' => ($card->tanggal_kirim && $card->tanggal_terima)
                ? (int) abs($card->tanggal_kirim->diffInDays($card->tanggal_terima))
                : null,
            'distanceKm' => ($lat && $lng && $origin->hasCoordinates())
                ? $origin->publicDistanceTo((float) $lat, (float) $lng)
                : null,
            'imageCid' => $image ? 'cid:'.$image->getContentId() : null,
            'viewUrl' => route('view', ['id' => $card->id]),
            'provenance' => self::provenanceLines(),
        ];
    }

    /**
     * The card's front photo as an inline attachment (skipped when missing
     * or over 2 MB), so it shows without loading anything remote.
     */
    protected function cardImage(Postcard $card): ?DataPart
    {
        $path = $card->foto_depan ? public_path($card->foto_depan) : null;

        if (! $path || ! is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';

        return DataPart::fromPath($path, 'postcard.jpg', $mime)->asInline();
    }

    /**
     * Optional provenance footer for automated mail (MAIL_PROVENANCE_FOOTER=true).
     *
     * @return list<string>
     */
    public static function provenanceLines(): array
    {
        if (! config('mail.provenance.enabled')) {
            return [];
        }

        return [
            sprintf('Generated by %s (arrival confirmation) on %s at %s',
                config('app.name', 'Postcard Tracker'),
                config('mail.provenance.host') ?: gethostname(),
                now()->utc()->format('Y-m-d\TH:i:s\Z')),
            'Source: '.(config('mail.provenance.source') ?: base_path('app/Livewire/ReceiveConfirm.php')),
            'Schedule: event-driven, sent when a recipient confirms arrival',
            'Automated alert, no human in the loop',
        ];
    }

    /**
     * Re-encode both bodies as base64: quoted-printable and 8bit bodies have
     * been seen silently truncated by intermediate SMTP hops.
     */
    public static function useBase64Body(Email $email, ?DataPart $image = null): void
    {
        $text = new TextPart((string) $email->getTextBody(), 'utf-8', 'plain', 'base64');
        $html = new TextPart((string) $email->getHtmlBody(), 'utf-8', 'html', 'base64');

        $email->setBody(new AlternativePart($text, $image ? new RelatedPart($html, $image) : $html));
    }

    public function render()
    {
        return view('livewire.receive-confirm')
            ->layout('components.layouts.app', ['title' => 'Postcard Arrival Registry']);
    }
}
