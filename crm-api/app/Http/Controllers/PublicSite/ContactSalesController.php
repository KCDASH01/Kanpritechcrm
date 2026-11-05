<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Mail\SalesEnquiryMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactSalesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9]{8,15}$/'],
            'company' => ['required', 'string', 'max:191'],
            'team_size' => ['nullable', 'string', 'max:50'],
            'plan' => ['nullable', 'in:growth,business,enterprise,not_sure'],
            'message' => ['required', 'string', 'max:2000'],
            'website' => ['nullable', 'max:0'],
        ]);

        $recipient = (string) config('services.sales_contact.email');
        $whatsAppNumber = preg_replace('/\D+/', '', (string) config('services.sales_contact.whatsapp'));
        abort_if($recipient === '' || $whatsAppNumber === '', 503, 'Sales contact is not configured.');

        $plan = str_replace('_', ' ', ucfirst($data['plan'] ?? 'not sure'));
        $body = implode("\n", [
            'New Growneq CRM sales enquiry',
            '',
            'Name: '.$data['name'],
            'Email: '.$data['email'],
            'Phone / WhatsApp: '.$data['phone'],
            'Company: '.$data['company'],
            'Team size: '.($data['team_size'] ?? 'Not provided'),
            'Plan: '.$plan,
            '',
            'Requirements:',
            $data['message'],
        ]);

        try {
            Mail::to($recipient)->send(new SalesEnquiryMail($data));
        } catch (Throwable $exception) {
            Log::warning('Contact sales email delivery failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'We could not send your enquiry right now. Please try again or contact us on WhatsApp.',
            ], 503);
        }

        return response()->json([
            'message' => 'Your sales enquiry has been emailed successfully.',
            'whatsapp_url' => 'https://wa.me/'.$whatsAppNumber.'?text='.rawurlencode($body),
        ], 201);
    }
}
