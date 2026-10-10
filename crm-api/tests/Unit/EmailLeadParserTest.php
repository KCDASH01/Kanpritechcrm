<?php

namespace Tests\Unit;

use App\Services\LeadIntegrations\EmailLeadParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailLeadParserTest extends TestCase
{
    public function test_it_extracts_only_structured_lead_fields(): void
    {
        $result = (new EmailLeadParser)->parse('Qualified Lead', "Client: Jane Smith\nEmail: jane@example.com\nPhone: +1 555 0100\nService: Website Development\nBudget: USD 3000");

        $this->assertSame('Jane Smith', $result['fields']['name']);
        $this->assertSame('jane@example.com', $result['fields']['email']);
        $this->assertSame('+1 555 0100', $result['fields']['phone']);
        $this->assertSame('Website Development', $result['fields']['service']);
    }

    public function test_it_does_not_parse_quoted_threads_or_signatures(): void
    {
        $result = (new EmailLeadParser)->parse('Forward', "Client: Safe Person\n-- \nEmail: signature@example.com\n> Phone: 9999999999");

        $this->assertSame('Safe Person', $result['fields']['name']);
        $this->assertArrayNotHasKey('email', $result['fields']);
        $this->assertArrayNotHasKey('phone', $result['fields']);
    }

    public function test_it_sanitizes_html_before_parsing(): void
    {
        $result = (new EmailLeadParser)->parse('Lead', '<p>Client: Ravi Kumar</p><p>Email: ravi@example.com</p><script>alert(1)</script>', true);

        $this->assertStringNotContainsString('<script>', $result['safe_body']);
    }

    #[DataProvider('classificationCases')]
    public function test_it_classifies_email_replies(string $text, string $expected): void
    {
        $result = (new EmailLeadParser)->parse('Re: Campaign', $text);
        $this->assertSame($expected, $result['classification']);
    }

    public static function classificationCases(): array
    {
        return [
            ['I am interested in your service.', 'interested'],
            ['Please send me more information.', 'requesting_information'],
            ['Can we schedule a meeting?', 'meeting_requested'],
            ['Please unsubscribe me.', 'unsubscribe'],
            ['Automatic reply: I am out of office.', 'out_of_office'],
            ['Mail delivery failed: undeliverable.', 'bounce'],
            ['No thanks, I am not interested.', 'not_interested'],
            ['Hello, I received your message.', 'needs_manual_review'],
        ];
    }
}
