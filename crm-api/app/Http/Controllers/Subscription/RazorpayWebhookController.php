<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionGatewayEvent;
use App\Services\RazorpaySubscriptionGateway;
use App\Services\SubscriptionWebhookService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RazorpayWebhookController extends Controller
{
    public function __construct(
        private readonly RazorpaySubscriptionGateway $gateway,
        private readonly SubscriptionWebhookService $processor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature');
        $eventId = (string) $request->header('X-Razorpay-Event-Id');
        abort_unless($eventId !== '' && $this->gateway->verifyWebhookSignature($raw, $signature), 401, 'Invalid webhook signature.');

        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        try {
            $event = SubscriptionGatewayEvent::create([
                'gateway' => 'razorpay',
                'event_id' => $eventId,
                'event_type' => (string) ($payload['event'] ?? 'unknown'),
                'gateway_subscription_id' => data_get($payload, 'payload.subscription.entity.id'),
                'payload_hash' => hash('sha256', $raw),
                'payload' => $payload,
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            throw $exception;
        }

        $this->processor->process($event);

        return response()->json(['received' => true]);
    }
}
