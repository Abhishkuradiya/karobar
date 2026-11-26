<?php

namespace App\Console\Commands;

use App\Models\AiAccountantSetting;
use Illuminate\Console\Command;

class ConfigureAiAccountant extends Command
{
    protected $signature = 'ai:configure';
    protected $description = 'Securely configure the global Omega Plus connection without exposing credentials to the browser';

    public function handle(): int
    {
        $apiKey = trim((string) $this->secret('Omega Plus API key (input is hidden)'));
        if ($apiKey === '') {
            $this->error('An API key is required. Nothing was changed.');
            return self::FAILURE;
        }

        AiAccountantSetting::updateOrCreate(
            ['id' => 1],
            [
                'provider' => 'omegaplus',
                'api_key' => $apiKey,
                'base_url' => 'https://api.omegaplusapi.com',
                'model' => 'claude-quantum-lite-1',
                'temperature' => 0.7,
                'max_tokens' => 2048,
                'last_verified_at' => null,
            ],
        );

        $this->info('Global Omega Plus credentials saved encrypted. The key is not returned by any API.');
        return self::SUCCESS;
    }
}
