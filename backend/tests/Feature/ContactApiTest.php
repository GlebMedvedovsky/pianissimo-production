<?php

namespace Tests\Feature;

use App\Mail\ContactInquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'contact.allowed_origin' => 'https://pianissimo-production.de',
            'contact.recipient' => 'server-recipient@example.test',
            'contact.minimum_fill_seconds' => 2,
            'cors.allowed_origins' => ['https://pianissimo-production.de'],
            'mail.default' => 'smtp',
            'mail.from.address' => 'website@example.test',
            'mail.from.name' => 'Pianissimo Production',
        ]);
        Mail::fake();
    }

    public function test_valid_submission_sends_plain_text_mail_to_server_recipient_with_visitor_reply_to(): void
    {
        $response = $this->submit();

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://pianissimo-production.de')
            ->assertExactJson(['ok' => true, 'message' => 'Your message has been sent.']);
        Mail::assertSent(ContactInquiry::class, function (ContactInquiry $mail): bool {
            return $mail->hasTo('server-recipient@example.test')
                && ! $mail->hasTo('attacker@example.test')
                && $mail->hasReplyTo('visitor@example.test')
                && $mail->envelope()->subject === 'Website inquiry from Visitor Name'
                && $mail->content()->text === 'mail.contact-inquiry'
                && $mail->attachments() === [];
        });
    }

    public function test_plain_text_mail_preserves_literal_visitor_text(): void
    {
        $mail = new ContactInquiry(
            'Sebastian & Team',
            'visitor@example.test',
            "Music & Film\nA < B",
        );

        $body = $mail->render();

        $this->assertStringContainsString('Sebastian & Team', $body);
        $this->assertStringContainsString("Music & Film\nA < B", $body);
        $this->assertStringNotContainsString('&amp;', $body);
        $this->assertStringNotContainsString('&lt;', $body);
        $this->assertStringNotContainsString('&gt;', $body);
        $this->assertSame([], $mail->attachments());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->submit(['email' => 'not-an-email'])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->submit(['message' => str_repeat('x', 15001)])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_honeypot_and_excessively_fast_submissions_are_rejected(): void
    {
        $this->submit(['website' => 'https://spam.example'])->assertStatus(422);
        $this->submit(['started_at' => (int) floor(microtime(true) * 1000)])->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_unexpected_fields_and_file_uploads_are_rejected(): void
    {
        $this->submit(['recipient' => 'attacker@example.test'])->assertStatus(422);
        $this->withHeader('Origin', 'https://pianissimo-production.de')
            ->post('/api/contact', $this->payload() + [
                'attachment' => UploadedFile::fake()->create('brief.pdf', 1, 'application/pdf'),
            ])
            ->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_contact_endpoint_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->submit()->assertOk();
        }
        $this->submit()->assertStatus(429);
        Mail::assertSent(ContactInquiry::class, 5);
    }

    public function test_submission_does_not_query_or_persist_to_the_database(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->submit()->assertOk();

        $this->assertSame(0, $queries);
    }

    public function test_unconfigured_mail_and_wrong_origins_fail_without_exposing_visitor_content(): void
    {
        $secretMessage = 'Private unreleased project details '.bin2hex(random_bytes(8));
        $secretEmail = 'private-contact@example.test';
        config(['mail.default' => 'log']);

        $response = $this->submit([
            'email' => $secretEmail,
            'message' => $secretMessage,
        ]);
        $response->assertStatus(503);
        $this->assertStringNotContainsString($secretMessage, $response->getContent());
        $this->assertStringNotContainsString($secretEmail, $response->getContent());

        config(['mail.default' => 'smtp']);
        $this->withHeader('Origin', 'https://evil.example')
            ->postJson('/api/contact', $this->payload())
            ->assertStatus(403);
        Mail::assertNothingSent();
    }

    private function submit(array $overrides = [])
    {
        return $this->withHeader('Origin', 'https://pianissimo-production.de')
            ->postJson('/api/contact', array_replace($this->payload(), $overrides));
    }

    private function payload(): array
    {
        return [
            'name' => 'Visitor Name',
            'email' => 'visitor@example.test',
            'message' => 'A detailed project inquiry for Sebastian.',
            'website' => '',
            'started_at' => (int) floor(microtime(true) * 1000) - 3000,
        ];
    }
}
