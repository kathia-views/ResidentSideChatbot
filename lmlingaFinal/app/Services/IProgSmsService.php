<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers outbound SMS through IPROG generic /sms_messages endpoint.
 * Does not generate, store, or verify OTPs.
 */
final class IProgSmsService
{
    public function sendSms(string $phoneNumber, string $message, ?int $requestId = null): IProgSmsSendResult
    {
        $token = trim((string) config('services.iprog.api_token', ''));
        $baseUrl = rtrim((string) config('services.iprog.base_url', 'https://www.iprogsms.com/api/v1'), '/');
        $timeout = max(1, (int) config('services.iprog.timeout', 10));

        if ($token === '') {
            $this->logFailure($requestId, IProgSmsSendResult::CATEGORY_CONFIGURATION, null);

            return IProgSmsSendResult::failed(IProgSmsSendResult::CATEGORY_CONFIGURATION);
        }

        $url = $baseUrl.'/sms_messages';

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'api_token' => $token,
                    'phone_number' => $phoneNumber,
                    'message' => $message,
                ]);
        } catch (ConnectionException $e) {
            $category = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'timeout')
                ? IProgSmsSendResult::CATEGORY_TIMEOUT
                : IProgSmsSendResult::CATEGORY_NETWORK;

            $this->logFailure($requestId, $category, null);

            return IProgSmsSendResult::failed($category);
        } catch (Throwable) {
            $this->logFailure($requestId, IProgSmsSendResult::CATEGORY_NETWORK, null);

            return IProgSmsSendResult::failed(IProgSmsSendResult::CATEGORY_NETWORK);
        }

        $httpStatus = $response->status();

        if (! $response->successful()) {
            $this->logFailure($requestId, IProgSmsSendResult::CATEGORY_HTTP, $httpStatus);

            return IProgSmsSendResult::failed(IProgSmsSendResult::CATEGORY_HTTP, $httpStatus);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->logFailure($requestId, IProgSmsSendResult::CATEGORY_MALFORMED, $httpStatus);

            return IProgSmsSendResult::failed(IProgSmsSendResult::CATEGORY_MALFORMED, $httpStatus);
        }

        $providerStatus = $payload['status'] ?? null;
        $messageId = trim((string) ($payload['message_id'] ?? ''));

        $statusOk = $providerStatus === 200
            || $providerStatus === '200'
            || $providerStatus === 'success';

        if (! $statusOk || $messageId === '') {
            $this->logFailure($requestId, IProgSmsSendResult::CATEGORY_PROVIDER, $httpStatus);

            return IProgSmsSendResult::failed(IProgSmsSendResult::CATEGORY_PROVIDER, $httpStatus);
        }

        Log::info('iprog.sms.queued', array_filter([
            'provider' => 'iprog',
            'request_id' => $requestId,
            'http_status' => $httpStatus,
            'message_id' => $messageId,
        ], static fn ($value) => $value !== null));

        return IProgSmsSendResult::queued($messageId, $httpStatus);
    }

    private function logFailure(?int $requestId, string $category, ?int $httpStatus): void
    {
        Log::warning('iprog.sms.failed', array_filter([
            'provider' => 'iprog',
            'request_id' => $requestId,
            'failure_category' => $category,
            'http_status' => $httpStatus,
        ], static fn ($value) => $value !== null));
    }
}
