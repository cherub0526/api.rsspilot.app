<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\VideoTranscriber;

use Tests\TestCase;
use App\Models\Config;
use Hypervel\Support\Facades\Http;
use Hypervel\Foundation\Testing\RefreshDatabase;

/**
 * @internal
 * @covers \App\Console\Commands\VideoTranscriber\Login
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function testLoginsWithTheGivenOptionsAndStoresTheToken(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['access_token' => 'token-1', 'user_name' => '黃麒錕'],
            ], 200),
        ]);

        $exitCode = $this->artisan('videotranscriber:login', [
            '--email'    => 'cherub0526@gmail.com',
            '--password' => 'secret',
        ])->run();

        $this->assertSame(0, $exitCode);
        $this->assertSame('token-1', Config::getValue(Config::KEY_VIDEOTRANSCRIBER)['access_token']);
    }

    public function testFallsBackToTheConfiguredCredentials(): void
    {
        config()->set('services.videotranscriber.email', 'env@example.com');
        config()->set('services.videotranscriber.password', 'env-secret');

        Http::fake([
            'videotranscriber.ai/*' => Http::response([
                'code'    => 100000,
                'message' => 'success',
                'data'    => ['access_token' => 'token-1'],
            ], 200),
        ]);

        $this->artisan('videotranscriber:login')->run();

        Http::assertSent(fn ($request) => $request['email'] === 'env@example.com'
            && $request['password'] === 'env-secret');
    }

    public function testFailsWhenNoCredentialsAreAvailable(): void
    {
        config()->set('services.videotranscriber.email', null);
        config()->set('services.videotranscriber.password', null);

        Http::fake();

        $exitCode = $this->artisan('videotranscriber:login')->run();

        $this->assertSame(1, $exitCode);
        Http::assertNothingSent();
    }

    public function testFailsWhenTheApiRejectsTheCredentials(): void
    {
        Http::fake([
            'videotranscriber.ai/*' => Http::response(['code' => 100001, 'message' => 'invalid credentials'], 200),
        ]);

        $exitCode = $this->artisan('videotranscriber:login', [
            '--email'    => 'cherub0526@gmail.com',
            '--password' => 'wrong',
        ])->run();

        $this->assertSame(1, $exitCode);
        $this->assertNull(Config::getValue(Config::KEY_VIDEOTRANSCRIBER));
    }
}
