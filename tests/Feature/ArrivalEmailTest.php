<?php

namespace Tests\Feature;

use App\Livewire\ReceiveConfirm;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Postcard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Home = London (51.507412, -0.127758), recipient = Paris (48.8566, 2.3522):
 * exact distance 344 km, public (rounded) distance 340 km.
 */
class ArrivalEmailTest extends TestCase
{
    use RefreshDatabase;

    private Postcard $card;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.home_lat' => 51.507412, 'app.home_lng' => -0.127758, 'app.owner_location' => 'London, UK',
            'app.owner_name' => 'Test Owner', 'currency.base' => 'USD', 'mail.provenance.enabled' => false,
        ]);

        $user = User::create(['username' => 'tester', 'password' => 'secret']);
        $country = Country::create(['nama_indonesia' => 'Prancis', 'nama_inggris' => 'France', 'kode_iso' => 'FR']);
        $contact = Contact::create(['user_id' => $user->id, 'nama_kontak' => 'Marie', 'alamat' => '1 Rue de Rivoli, Paris', 'country_id' => $country->id, 'lat' => 48.8566, 'lng' => 2.3522]);

        $this->card = Postcard::create([
            'user_id' => $user->id,
            'uid' => (string) Str::ulid(),
            'type' => 'sent',
            'postcard_id' => 'US-1234567',
            'contact_id' => $contact->id,
            'country_id' => $country->id,
            'tanggal_kirim' => '2024-03-15',
        ]);
    }

    private function useMailConfig(?string $ownerEmail, ?string $fromAddress): void
    {
        config([
            'mail.default' => 'array',
            'app.owner_email' => $ownerEmail,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => 'Tracker',
        ]);
        Mail::purge('array'); // rebuild the mailer so it picks up the "from" above
    }

    private function confirm(): void
    {
        $this->travelTo(Carbon::parse('2024-04-02 10:00:00', 'UTC'));

        Livewire::test(ReceiveConfirm::class, ['uid' => $this->card->uid])
            ->set('message', "Arrived safely!\nThank you & greetings")
            ->call('confirm')
            ->assertSet('justConfirmed', true);
    }

    private function sentMessages()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages();
    }

    public function test_skips_the_email_and_logs_a_warning_when_owner_email_is_unset(): void
    {
        $this->useMailConfig(null, 'tracker@example.test');
        Log::spy();

        $this->confirm();

        $this->assertCount(0, $this->sentMessages());
        Log::shouldHaveReceived('warning')->once()
            ->with('Arrival email not sent: OWNER_EMAIL is not set.', ['postcard_id' => $this->card->id]);
        // The recipient's confirmation is still recorded.
        $this->assertSame('2024-04-02', $this->card->fresh()->tanggal_terima->format('Y-m-d'));
    }

    public function test_skips_the_email_when_the_sender_address_is_unset(): void
    {
        $this->useMailConfig('owner@example.test', null);
        Log::spy();

        $this->confirm();

        $this->assertCount(0, $this->sentMessages());
        Log::shouldHaveReceived('warning')->once()
            ->with('Arrival email not sent: MAIL_FROM_ADDRESS is not set.', ['postcard_id' => $this->card->id]);
    }

    public function test_sends_a_postcard_themed_email_with_a_plain_text_part(): void
    {
        $this->useMailConfig('owner@example.test', 'tracker@example.test');

        $this->confirm();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();

        $this->assertSame(['owner@example.test'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('tracker@example.test', $email->getFrom()[0]->getAddress());
        $this->assertStringContainsString('France', $email->getSubject());

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        foreach (['US-1234567', 'France', 'London, UK', '18 days', '~340 km', 'Marie'] as $expected) {
            $this->assertStringContainsString($expected, $html, $expected);
        }
        $this->assertStringContainsString('Arrived safely!<br />', $html);
        $this->assertStringContainsString('Thank you &amp; greetings', $html);

        $this->assertStringContainsString('Postcard:        US-1234567', $text);
        $this->assertStringContainsString('Sent from:       London, UK', $text);
        $this->assertStringContainsString('Days in transit: 18', $text);
        $this->assertStringContainsString('Distance:        ~340 km', $text);
        $this->assertStringContainsString('Thank you & greetings', $text);

        // No exact coordinates or exact distance anywhere in the message
        $raw = $messages->first()->toString();
        foreach (['51.507', '0.1277', '48.8566', '2.3522', '344'] as $secret) {
            $this->assertStringNotContainsString($secret, $html.$text, $secret);
        }

        // Both parts are base64 (no quoted-printable), text alongside HTML
        $this->assertSame(2, substr_count($raw, 'Content-Transfer-Encoding: base64'));
        $this->assertStringNotContainsString('quoted-printable', $raw);
        $this->assertStringContainsString('multipart/alternative', $raw);

        // No provenance footer unless enabled
        $this->assertStringNotContainsString('no human in the loop', $text);
    }

    public function test_provenance_footer_when_enabled(): void
    {
        config(['mail.provenance.enabled' => true, 'mail.provenance.host' => 'dock-test', 'mail.provenance.source' => '/srv/app/app/Livewire/ReceiveConfirm.php']);
        $this->useMailConfig('owner@example.test', 'tracker@example.test');

        $this->confirm();

        $email = $this->sentMessages()->first()->getOriginalMessage();
        $expected = [
            'Generated by Postcard Tracker (arrival confirmation) on dock-test at 2024-04-02T10:00:00Z',
            'Source: /srv/app/app/Livewire/ReceiveConfirm.php',
            'Schedule: event-driven, sent when a recipient confirms arrival',
            'Automated alert, no human in the loop',
        ];
        $this->assertStringContainsString("--\n".implode("\n", $expected), $email->getTextBody());
        foreach ($expected as $line) {
            $this->assertStringContainsString($line, $email->getHtmlBody());
        }
    }

    public function test_embeds_the_card_photo_inline(): void
    {
        $file = public_path('arrival-email-test.jpg');
        file_put_contents($file, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k='));
        $this->card->update(['foto_depan' => 'arrival-email-test.jpg']);
        $this->useMailConfig('owner@example.test', 'tracker@example.test');

        try {
            $this->confirm();

            // The photo is read when the message is serialized, so assert before deleting it
            $raw = $this->sentMessages()->first()->toString();
            $email = $this->sentMessages()->first()->getOriginalMessage();
            $this->assertMatchesRegularExpression('/src="cid:[^"]+"/', $email->getHtmlBody());
            $this->assertStringContainsString('multipart/related', $raw);
            $this->assertStringContainsString('Content-Disposition: inline', $raw);
        } finally {
            @unlink($file);
        }
    }
}
