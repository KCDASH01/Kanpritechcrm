<?php

namespace App\Services\LeadImports;

use App\Models\Lead;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Str;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class ContactKeys
{
    public static function phone(?string $phone, ?string $country): ?string
    {
        $phone = trim($phone ?? '');
        if (! preg_match('/^[+0-9().\p{Z}\s-]+$/uD', $phone)) {
            return null;
        }
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        $region = ImportFields::country($country);
        $utility = PhoneNumberUtil::getInstance();
        try {
            $number = $utility->parse($phone, $region);

            return $utility->isValidNumber($number) ? $utility->format($number, PhoneNumberFormat::E164) : null;
        } catch (NumberParseException) {
            return null;
        }
    }

    public function keys(array $row): array
    {
        $email = Str::lower(trim($row['email'] ?? ''));
        $phone = self::phone($row['phone'] ?? null, $row['country'] ?? null);
        if (! $phone && empty($row['country']) && preg_match('/^[6-9][0-9]{9}$/D', $row['phone'] ?? '')) {
            // The manual Lead request accepts only Indian mobile numbers as bare ten digits.
            $phone = '+91'.$row['phone'];
        }
        $website = $this->website($row['website'] ?? null);

        return ['email' => $email, 'phone' => $phone, 'website' => $website];
    }

    public function existing(int $organization, array $rows, bool $includeWebsites = false): array
    {
        $emails = [];
        $phones = [];
        $websites = [];
        $websiteValues = [];
        foreach ($rows as $row) {
            $keys = $this->keys($row['attributes']);
            if ($keys['email']) $emails[$keys['email']] = true;
            if ($keys['phone']) {
                $utility = PhoneNumberUtil::getInstance();
                $number = $utility->parse($keys['phone']);
                $forms = [$row['attributes']['phone'] ?? null, $keys['phone'], $utility->format($number, PhoneNumberFormat::NATIONAL)];
                foreach ($forms as $form) {
                    $candidate = PhoneNormalizer::normalize($form);
                    if ($candidate) {
                        $phones[$candidate] = true;
                    }
                }
            }
            if ($keys['website']) {
                $websites[$keys['website']] = true;
                $websiteValues[$row['attributes']['website']] = true;
            }
        }
        $matches = ['email' => [], 'phone' => [], 'website' => []];
        foreach (['lead_import_email_key' => $emails, 'phone_normalized' => $phones] as $column => $keys) {
            foreach (array_chunk(array_keys($keys), 500) as $chunk) {
                $contacts = Lead::where('organization_id', $organization)->whereIn($column, $chunk)
                    ->select(['id', 'email', 'phone', 'country'])->cursor();
                foreach ($contacts as $contact) {
                    $this->remember($matches, $this->keys($contact->getAttributes()));
                }
            }
        }
        if ($includeWebsites && $websites) {
            // Website is advisory. Search exact host variants in a bounded tenant query.
            $variants = array_keys($websiteValues);
            foreach (array_keys($websites) as $host) {
                foreach (['', 'https://', 'http://', 'https://www.', 'http://www.', 'www.'] as $prefix) {
                    $variants[] = $prefix.$host;
                    $variants[] = $prefix.$host.'/';
                }
            }
            foreach (array_chunk(array_unique($variants), 500) as $chunk) {
                $found = Lead::where('organization_id', $organization)->whereIn('website', $chunk)
                    ->distinct()->pluck('website');
                foreach ($found as $website) {
                    $matches['website'][$this->website($website)] = true;
                }
            }
        }

        return $matches;
    }

    public function remember(array &$seen, array $keys): void
    {
        foreach ($keys as $field => $value) {
            if ($value) {
                $seen[$field][$value] = true;
            }
        }
    }

    private function website(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', Str::lower($host)) : null;
    }
}
