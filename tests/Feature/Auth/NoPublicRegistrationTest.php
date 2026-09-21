<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NoPublicRegistrationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function registrationPaths(): array
    {
        return [
            'register' => ['/register'],
            'sign up' => ['/signup'],
            'create account' => ['/create-account'],
        ];
    }

    #[DataProvider('registrationPaths')]
    public function test_no_public_registration_page_exists(string $path): void
    {
        $this->get($path)->assertNotFound();
        $this->post($path, ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Passw0rd-123'])->assertNotFound();
    }

    public function test_no_registration_route_is_defined(): void
    {
        $this->assertFalse(Route::has('register'));
    }
}
