<?php

declare(strict_types=1);

namespace App\Console\Commands\VideoTranscriber;

use Hypervel\Console\Command;
use App\Services\VideoTranscriber\VideoTranscriberClient;

class Login extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'videotranscriber:login
        {--email= : Login email, defaults to services.videotranscriber.email}
        {--password= : Login password, defaults to services.videotranscriber.password}';

    /**
     * The console command description.
     */
    protected string $description = 'Log in to videotranscriber.ai and store the access token in configs';

    /**
     * Execute the console command.
     */
    public function handle(VideoTranscriberClient $client): int
    {
        $email = $this->option('email') ?: config('services.videotranscriber.email');
        $password = $this->option('password') ?: config('services.videotranscriber.password');

        if (empty($email) || empty($password)) {
            $this->error('Email and password are required.');

            return 1;
        }

        $result = $client->login($email, $password);

        if (($result['code'] ?? null) !== 100000) {
            $this->error('Login failed: ' . ($result['message'] ?? 'unknown error'));

            return 1;
        }

        $this->info('Logged in as ' . ($result['data']['user_name'] ?? $email));

        return 0;
    }
}
