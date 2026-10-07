<?php

namespace Tests\Feature\Models;

use App\Models\ContactEnquiry;
use Illuminate\Database\QueryException;
use Tests\MysqlTestCase;

class ContactEnquiryTest extends MysqlTestCase
{
    // --- status allowed list ---------------------------------------------------

    public function test_the_database_rejects_a_status_outside_the_allowed_list(): void
    {
        $this->assertThrows(
            fn () => ContactEnquiry::factory()->create(['status' => 'spam']),
            QueryException::class,
        );

        $this->assertSame(0, ContactEnquiry::query()->count());
    }
}
