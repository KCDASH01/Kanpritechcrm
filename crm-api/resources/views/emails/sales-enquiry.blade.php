New Growneq CRM sales enquiry

Name: {{ $enquiry['name'] }}
Email: {{ $enquiry['email'] }}
Phone / WhatsApp: {{ $enquiry['phone'] }}
Company: {{ $enquiry['company'] }}
Team size: {{ $enquiry['team_size'] ?? 'Not provided' }}
Plan: {{ str_replace('_', ' ', ucfirst($enquiry['plan'] ?? 'not sure')) }}

Requirements:
{{ $enquiry['message'] }}
