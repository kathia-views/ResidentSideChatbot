<?php

namespace Tests\Unit;

use App\Services\IProgSmsSendResult;
use App\Services\IProgSmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IProgSmsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.iprog.base_url', 'https://www.iprogsms.com/api/v1');
        Config::set('services.iprog.api_token', 'test-iprog-token');
        Config::set('services.iprog.timeout', 5);
        Http::preventStrayRequests();
    }

    public function test_successful_queue_posts_json_body_without_token_in_url(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                'message_id' => 'iSms-XHYBk',
            ], 200),
        ]);

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'Your LMLinga verification code is 123456. It expires in 5 minutes.', 42);

        $this->assertTrue($result->queued);
        $this->assertSame('iSms-XHYBk', $result->messageId);
        $this->assertSame(IProgSmsSendResult::CATEGORY_QUEUED, $result->failureCategory);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://www.iprogsms.com/api/v1/sms_messages', $request->url());
            $this->assertStringNotContainsString('api_token=', $request->url());
            $this->assertSame('test-iprog-token', $request['api_token']);
            $this->assertSame('09171234567', $request['phone_number']);
            $this->assertStringContainsString('123456', $request['message']);
            $this->assertFalse($request->hasHeader('Authorization'));

            return true;
        });
    }

    public function test_missing_api_token_fails_without_http(): void
    {
        Config::set('services.iprog.api_token', '');
        Http::fake();

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_CONFIGURATION, $result->failureCategory);
        Http::assertNothingSent();
    }

    public function test_provider_failure_body_fails_safely(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 500,
                'message' => 'Invalid Token',
            ], 200),
        ]);

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_PROVIDER, $result->failureCategory);
    }

    public function test_malformed_json_fails_safely(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response('not-json', 200),
        ]);

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_MALFORMED, $result->failureCategory);
    }

    public function test_http_error_status_fails_safely(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response(['status' => 500], 500),
        ]);

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_HTTP, $result->failureCategory);
        $this->assertSame(500, $result->httpStatus);
    }

    public function test_missing_message_id_fails_safely(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'queued',
            ], 200),
        ]);

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_PROVIDER, $result->failureCategory);
    }

    public function test_connection_exception_fails_safely(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_TIMEOUT, $result->failureCategory);
    }

    public function test_generic_connection_exception_is_network_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host');
        });

        $result = app(IProgSmsService::class)->sendSms('09171234567', 'msg');

        $this->assertFalse($result->queued);
        $this->assertSame(IProgSmsSendResult::CATEGORY_NETWORK, $result->failureCategory);
    }
}
