<?php

namespace App\Services\LeadImports;

use App\Http\Requests\Lead\LeadRequest;
use App\Models\BillingCatalogueItem;
use App\Models\BillingProfile;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadCreationService;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportFields
{
    public const LABELS = [
        'lead_date' => 'Lead Date', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'full_name' => 'Full Name',
        'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company', 'website' => 'Website',
        'job_title' => 'Job Title', 'city' => 'City', 'state' => 'State / Province', 'country' => 'Country',
        'market_type' => 'Market Type', 'client_type' => 'Client Type', 'service' => 'Service / Product',
        'business_type' => 'Business Type', 'source' => 'Source', 'status' => 'Status',
        'expected_value' => 'Expected Value', 'currency' => 'Currency', 'current_status' => 'Current Status',
        'call_status' => 'Call Status', 'whatsapp_status' => 'WhatsApp Status', 'notes' => 'Notes',
        'recurring_frequency' => 'Recurring Frequency', 'recurring_amount' => 'Recurring Amount',
        'recurring_start_date' => 'Recurring Start Date', 'recurring_end_type' => 'Recurring End Type',
        'recurring_end_date' => 'Recurring End Date',
    ];

    private const ALIASES = [
        'assigned_date' => 'lead_date', 'date' => 'lead_date', 'name' => 'full_name', 'contact_name' => 'full_name',
        'contact_person' => 'full_name', 'email_address' => 'email', 'mobile' => 'phone', 'phone_whatsapp' => 'phone',
        'phone_number' => 'phone', 'whatsapp_number' => 'phone', 'website_url' => 'website', 'organization' => 'company',
        'organisation' => 'company', 'company_name' => 'company', 'state_province' => 'state', 'province' => 'state',
        'service' => 'service', 'service_product' => 'service', 'requirement' => 'service',
        'service_type' => 'service', 'product' => 'service',
    ];

    private const LEGACY = [
        'webapp_development' => 'Webapp Development', 'mobile_app_development' => 'Mobile App Development',
        'website_development' => 'Website Development', 'digital_marketing' => 'Digital Marketing', 'others' => 'Others',
    ];

    public function __construct(private LeadCreationService $creation) {}

    public function services(User $user): array
    {
        $legacy = [];
        foreach (self::LEGACY as $code => $label) {
            $legacy['legacy:'.$code] = ['label' => $label, 'types' => $code];
        }
        $catalogue = BillingCatalogueItem::where('organization_id', $user->organization_id)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'code'])->keyBy(fn ($item) => 'catalogue:'.$item->id)
            ->map(fn ($item) => ['label' => $item->name, 'code' => $item->code, 'types' => $item->name, 'catalogue_item_id' => $item->id]);

        return $legacy + $catalogue->all();
    }

    public function suggestions(array $headers): array
    {
        $indices = [];
        foreach ($headers as $index => $header) {
            $key = self::key($header);
            $field = self::ALIASES[$key] ?? (isset(self::LABELS[$key]) ? $key : null);
            if ($field) {
                $indices[$field][] = $index;
            }
        }
        $map = [];
        $conflicts = [];
        foreach ($indices as $field => $columns) {
            if (count($columns) === 1) {
                $map[$columns[0]] = $field;
            } else {
                $conflicts = [...$conflicts, ...$columns];
            }
        }

        return ['map' => (object) $map, 'conflicts' => $conflicts];
    }

    public function mapRow(array $row, array $map, ?string &$previousDate, bool $fillDown): ?array
    {
        $substantive = array_filter($row, fn ($cell) => $cell['text'] !== '' || $cell['kind'] === 'unsafe');
        if (! $substantive) {
            $previousDate = null;

            return null;
        }
        $values = [];
        $issues = [];
        foreach ($map as $index => $field) {
            $cell = $row[$index] ?? ['text' => '', 'kind' => 'text'];
            if ($cell['kind'] === 'unsafe') {
                $issues[$field] = 'Replace the formula or spreadsheet error with a plain value.';
            }
            if ($field === 'phone' && $cell['kind'] === 'large_number') {
                $issues[$field] = 'The spreadsheet may have rounded this phone number. Enter its exact digits in a text cell.';
            }
            if ($cell['text'] !== '') {
                $values[$field] = $cell['text'];
            }
        }
        $first = reset($substantive);
        if (! array_filter(array_intersect_key($values, array_flip(['first_name', 'last_name', 'full_name', 'email', 'phone', 'company', 'website', 'notes', 'service', 'current_status', 'call_status', 'whatsapp_status']))) && ! $issues) {
            $previousDate = null;

            return null;
        }
        if (count($substantive) === 1 && preg_match('/^(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\s-]+\d{4}$/i', $first['text'])) {
            $previousDate = null;

            return null;
        }
        if ($fillDown && ! isset($values['lead_date']) && $previousDate !== null) {
            $values['lead_date'] = $previousDate;
        }
        if (isset($values['lead_date'])) {
            $previousDate = $values['lead_date'];
        }

        return ['values' => $values, 'issues' => $issues];
    }

    public function defaults(User $user): array
    {
        $currency = BillingProfile::where('organization_id', $user->organization_id)->value('default_currency');

        return ['client_type' => 'NEW', 'business_type' => 'ONE_TIME', 'source' => 'csv', 'status' => 'new',
            'currency' => in_array($currency, ['INR', 'USD'], true) ? $currency : null];
    }

    public function prepare(User $user, array $values, array $setup, array $services): array
    {
        $values = array_replace($setup['defaults'], $values);
        if (empty($values['currency'])) {
            unset($values['currency']);
        }
        $fullName = $values['full_name'] ?? '';
        if (empty($values['first_name']) && $fullName !== '') {
            [$values['first_name'], $lastName] = array_pad(preg_split('/\s+/u', $fullName, 2), 2, null);
            $values['last_name'] ??= $lastName;
        }
        if (empty($values['first_name']) || ! preg_match('/\p{L}/u', $values['first_name'])) {
            throw ValidationException::withMessages(['first_name' => 'Enter a usable first or full name.']);
        }
        if (empty($values['email']) && empty($values['phone'])) {
            throw ValidationException::withMessages(['contact' => 'Enter an email address or phone number.']);
        }
        if (isset($values['email'])) {
            $values['email'] = Str::lower($values['email']);
        }

        $rawPhone = $values['phone'] ?? null;
        if ($rawPhone !== null) {
            $key = ContactKeys::phone($rawPhone, $values['country'] ?? null);
            if ($key === null || mb_strlen($rawPhone) > 30) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid phone number with a country, or use + and the country calling code.']);
            }
            $values['phone'] = $key;
        }

        foreach (['lead_date', 'recurring_start_date', 'recurring_end_date'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = $this->date($values[$field], $setup['date_format'], $field);
            }
        }
        $values['lead_date'] ??= $setup['date_fallback'];
        $home = self::country($user->organization->settings['home_country'] ?? $user->organization->country);
        $country = self::country($values['country'] ?? null);
        if (empty($values['market_type']) && $home && $country) {
            $values['market_type'] = $home === $country ? 'DOMESTIC' : 'INTERNATIONAL';
        }
        if (empty($values['market_type'])) {
            throw ValidationException::withMessages(['market_type' => 'Select a default market type or provide a country the CRM can compare to the organization home country.']);
        }

        foreach (['market_type', 'client_type', 'business_type', 'recurring_frequency', 'recurring_end_type', 'currency'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = strtoupper(self::key($values[$field]));
            }
        }
        $values['business_type'] = match ($values['business_type']) {
            'ONETIME', 'ONE_OFF' => 'ONE_TIME',
            default => $values['business_type'],
        };
        $values['client_type'] = match ($values['client_type']) {
            'NEW_CLIENT' => 'NEW', 'EXISTING_CLIENT' => 'EXISTING',
            default => $values['client_type'],
        };
        if ($values['client_type'] === 'EXISTING') {
            $client = $this->existingClient($user, $values);
            $values = array_replace($values, $this->creation->clientSnapshot($client), ['client_id' => $client->id]);
            $rawPhone = $client->phone;
        }
        $values['status'] = self::key($values['status']);
        if ($values['status'] === 'follow_up') {
            $values['status'] = 'followup';
        }
        if (in_array($values['status'], ['converted', 'followup', 'meeting'], true)) {
            throw ValidationException::withMessages(['status' => 'Import as an ordinary lead status. Conversion and scheduled statuses must be set through the CRM workflow.']);
        }
        $values['source'] = match (self::key($values['source'])) {
            'csv_import', 'spreadsheet', 'spreadsheet_import' => 'csv',
            default => self::key($values['source']),
        };

        $originalService = $values['service'] ?? '';
        $service = $this->service($originalService, $setup['service_aliases'], $services);
        $values['types'] = $service['types'];
        if (isset($service['catalogue_item_id'])) {
            $values['catalogue_item_id'] = $service['catalogue_item_id'];
        }

        $notes = [$values['notes'] ?? ''];
        foreach (['current_status', 'call_status', 'whatsapp_status'] as $field) {
            if (! empty($values[$field])) {
                $notes[] = self::LABELS[$field].': '.$values[$field];
            }
        }
        if ($originalService !== '' && ! isset($services[$originalService]) && strcasecmp($originalService, $service['label']) !== 0) {
            $notes[] = 'Spreadsheet service: '.mb_substr($originalService, 0, 255);
        }
        if ($fullName !== '') {
            $notes[] = 'Spreadsheet name: '.mb_substr($fullName, 0, 383);
        }
        $values['notes'] = implode("\n", array_filter($notes));
        if (strlen($values['notes']) > 60000) {
            throw ValidationException::withMessages(['notes' => 'The combined notes exceed 60 KB. Shorten the notes before importing this row.']);
        }
        $validated = LeadRequest::validateImport($user, $values);
        if ($rawPhone !== null) {
            $validated['phone'] = $rawPhone;
        }

        return ['attributes' => $validated, 'lead_date' => $values['lead_date']];
    }

    private function existingClient(User $user, array $values): \App\Models\Client
    {
        $matches = $this->creation->visibleClients($user)->where(fn ($query) => $query
            ->whereNotNull('email')->where('email', $values['email'] ?? '')
            ->orWhere(fn ($phone) => $phone->whereNotNull('phone_normalized')->where('phone_normalized', \App\Support\PhoneNormalizer::normalize($values['phone'] ?? null))))
            ->limit(20)->get()->filter(fn ($client) => (! empty($values['email']) && Str::lower((string) $client->email) === $values['email'])
                || (! empty($values['phone']) && ContactKeys::phone($client->phone, $client->country) === $values['phone']));
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['client_type' => 'An existing-client lead must match exactly one Client you can access by email or phone.']);
        }

        return $matches->first();
    }

    private function service(string $value, array $aliases, array $services): array
    {
        $choice = $aliases[self::key($value)] ?? $value;
        if (isset($services[$choice])) {
            return $services[$choice];
        }
        $matches = array_filter($services, fn ($service) => $value !== '' && (
            $service['label'] === $value || ($service['code'] ?? $service['types']) === $value
        ));
        if (! $matches && $value !== '') {
            $key = self::key($value);
            $matches = array_filter($services, fn ($service) => self::key($service['label']) === $key
                || self::key($service['code'] ?? $service['types']) === $key);
        }
        if (count($matches) === 1) {
            return reset($matches);
        }
        throw ValidationException::withMessages([
            'service' => $value === '' ? 'Choose a default service or map a service column.'
                : (count($matches) ? 'Multiple services match. Add an explicit service mapping.' : 'Service not recognized. Add an explicit service mapping.'),
        ]);
    }

    private function date(string $value, string $order, string $field): string
    {
        $formats = ['Y-m-d', 'd-M-y', 'd-M-Y', $order.'/Y', $order.'/y', str_replace('/', '-', $order).'-Y'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && $errors === false && $date->format('Y') >= '1900') {
                return $date->format('Y-m-d');
            }
        }
        throw ValidationException::withMessages([$field => 'Enter a valid date matching the selected date format.']);
    }

    public static function key(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '_', Str::lower(trim($value))) ?? '', '_');
    }

    public static function country(?string $name): ?string
    {
        if (! $name) {
            return null;
        }
        $key = self::key($name);
        $aliases = ['uk' => 'GB', 'usa' => 'US', 'united_states_of_america' => 'US', 'uae' => 'AE'];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }
        if (! class_exists(\Locale::class)) {
            return match ($key) {
                'india', 'in' => 'IN', 'united_states', 'us' => 'US', 'united_kingdom', 'gb' => 'GB', default => null,
            };
        }
        static $names;
        if ($names === null) {
            $names = [];
            foreach (range('A', 'Z') as $first) {
                foreach (range('A', 'Z') as $last) {
                    $code = $first.$last;
                    $label = \Locale::getDisplayRegion('und_'.$code, 'en');
                    if ($label && $label !== $code) {
                        $names[self::key($label)] = $code;
                        $names[strtolower($code)] = $code;
                    }
                }
            }
        }

        return $names[$key] ?? null;
    }
}
