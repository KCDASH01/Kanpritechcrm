<?php

namespace Tests\Feature;

use App\Mail\SalesEnquiryMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ContactSalesTest extends TestCase
{
    public function test_sales_enquiry_is_emailed_and_returns_whatsapp_link(): void
    {
        Mail::fake();
        config()->set('mail.from.address', 'sales@kanpritech.com');
        config()->set('mail.from.name', 'Growneq CRM');
        config()->set('services.sales_contact.email', 'contact@kanpritech.com');
        config()->set('services.sales_contact.whatsapp', '917751973970');

        $response = $this->postJson('/api/contact-sales', [
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'phone' => '+919876543210',
            'company' => 'Example Company',
            'team_size' => '11-25',
            'plan' => 'enterprise',
            'message' => 'We need a product demonstration.',
            'website' => '',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Your sales enquiry has been emailed successfully.')
            ->assertJsonPath('whatsapp_url', fn ($url) => str_starts_with($url, 'https://wa.me/917751973970?text='));
        Mail::assertSent(SalesEnquiryMail::class, fn ($mail) => $mail->hasTo('contact@kanpritech.com')
            && $mail->hasFrom('sales@kanpritech.com', 'Growneq CRM')
            && $mail->hasReplyTo('buyer@example.com'));
        Mail::assertSentCount(1);
    }

    public function test_sales_enquiry_rejects_invalid_input_and_honeypot(): void
    {
        Mail::fake();

        $this->postJson('/api/contact-sales', [
            'name' => '',
            'email' => 'invalid',
            'phone' => '123',
            'company' => '',
            'message' => '',
            'website' => 'spam.example',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'company', 'message', 'website']);

        Mail::assertNothingSent();
    }

    public function test_sales_enquiry_returns_safe_error_when_delivery_fails(): void
    {
        config()->set('services.sales_contact.email', 'contact@kanpritech.com');
        config()->set('services.sales_contact.whatsapp', '917751973970');
        Log::spy();
        Mail::shouldReceive('to')
            ->once()
            ->with('contact@kanpritech.com')
            ->andThrow(new RuntimeException('Simulated provider failure containing no real enquiry.'));

        $response = $this->postJson('/api/contact-sales', [
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'phone' => '+919876543210',
            'company' => 'Example Company',
            'team_size' => '11-25',
            'plan' => 'enterprise',
            'message' => 'We need a product demonstration.',
            'website' => '',
        ]);

        $response->assertStatus(503)
            ->assertExactJson([
                'message' => 'We could not send your enquiry right now. Please try again or contact us on WhatsApp.',
            ]);
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Contact sales email delivery failed.', [
                'exception' => RuntimeException::class,
            ]);
    }
}
