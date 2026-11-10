<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_reversed_interval_outside_http_validation(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('bookings_positive_interval');

        Booking::factory()->create(['starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 07:00:00']);
    }
}
