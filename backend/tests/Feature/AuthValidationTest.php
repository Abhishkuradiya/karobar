<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_identifiers_and_rejects_duplicate_email_or_username(): void
    {
        $this->postJson('/api/register', [
            'username' => 'New.Owner',
            'email' => 'Owner@Example.test',
            'password' => 'password123',
            'name' => 'New Owner',
        ])->assertCreated();

        $this->postJson('/api/register', [
            'username' => 'another-owner',
            'email' => 'owner@example.test',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [
            'username' => 'NEW.OWNER',
            'email' => 'different@example.test',
            'password' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('username');
    }
}
