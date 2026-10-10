<?php

namespace App\Services\LeadIntegrations;

class EmailLeadParser
{
    /** @return array{classification: string, fields: array<string, string>, safe_body: string} */
    public function parse(string $subject, string $body, bool $isHtml = false): array
    {
        $text = $isHtml ? html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5) : $body;
        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        $text = $this->removeQuotedContent($text);
        $combined = mb_strtolower($subject."\n".$text);

        $classification = match (true) {
            preg_match('/\b(mail delivery|delivery status notification|undeliverable|message blocked|mailer-daemon)\b/i', $combined) === 1 => 'bounce',
            preg_match('/\b(out of office|automatic reply|auto.?reply|away from (?:the )?office)\b/i', $combined) === 1 => 'out_of_office',
            preg_match('/\b(unsubscribe|remove me|stop emailing)\b/i', $combined) === 1 => 'unsubscribe',
            preg_match('/\b(not interested|no thanks|do not contact)\b/i', $combined) === 1 => 'not_interested',
            preg_match('/\b(schedule|meeting|call me|book a call)\b/i', $combined) === 1 => 'meeting_requested',
            preg_match('/\b(tell me more|please share|send (?:me )?(?:more )?information|more details|pricing details)\b/i', $combined) === 1 => 'requesting_information',
            preg_match('/\b(interested|pricing)\b/i', $combined) === 1 => 'interested',
            default => 'needs_manual_review',
        };

        $fields = [];
        $aliases = [
            'name' => ['client', 'contact', 'name', 'full name'],
            'email' => ['email', 'email address'],
            'phone' => ['phone', 'mobile', 'contact number'],
            'company' => ['company', 'organization'],
            'country' => ['country'],
            'state' => ['state'],
            'city' => ['city'],
            'service' => ['service', 'requirement'],
            'budget' => ['budget'],
        ];

        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            if (! preg_match('/^\s*([a-z][a-z ]{1,30})\s*:\s*(.+?)\s*$/i', $line, $match)) {
                continue;
            }
            $label = mb_strtolower(trim($match[1]));
            foreach ($aliases as $field => $labels) {
                if (in_array($label, $labels, true)) {
                    $fields[$field] = trim($match[2]);
                    break;
                }
            }
        }

        return ['classification' => $classification, 'fields' => $fields, 'safe_body' => trim($text)];
    }

    private function removeQuotedContent(string $body): string
    {
        $lines = [];
        foreach (preg_split('/\n/', $body) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '>')
                || preg_match('/^On .+ wrote:$/i', trim($line))
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', trim($line))) {
                break;
            }
            if (preg_match('/^--\s*$/', trim($line))) {
                break;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
