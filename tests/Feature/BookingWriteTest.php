<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class BookingWriteTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'room_id' => $overrides['room_id'] ?? Room::factory()->create()->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ], $overrides);
    }

    public function test_creates_booking_with_authenticated_author_and_utc_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(12, 0));
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = $this->payload(['user_id' => $other->id]);
        $logger = \Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('bookings')->once()->andReturn($logger);
        $logger->shouldReceive('info')->once()->with('Booking created', \Mockery::on(fn (array $context): bool => isset($context['booking_id'])));

        $response = $this->postJson('/api/bookings', $payload);

        $response->assertCreated()->assertExactJson([
            'id' => $response->json('id'),
            'room_id' => $payload['room_id'],
            'user_id' => $user->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T07:00:00.000000Z',
            'ends_at' => '2026-11-10T08:00:00.000000Z',
            'created_at' => '2026-11-11T12:00:00.000000Z',
            'updated_at' => '2026-11-11T12:00:00.000000Z',
        ]);
        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'), 'user_id' => $user->id,
            'starts_at' => '2026-11-10 07:00:00', 'ends_at' => '2026-11-10 08:00:00',
        ]);
        $this->getJson('/api/bookings')->assertJsonPath('data.0.starts_at', '2026-11-10T07:00:00.000000Z');
    }

    public function test_returns_422_for_missing_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->assertDatabaseCount('bookings', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_returns_422_for_invalid_input(array $overrides, string $field): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->payload($overrides);

        $this->postJson('/api/bookings', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'SQL room injection' => [['room_id' => '1 OR 1=1 --'], 'room_id'],
            'SQL start injection' => [['starts_at' => "2026-11-10' OR true --"], 'starts_at'],
            'SQL end injection' => [['ends_at' => "2026-11-10' OR true --"], 'ends_at'],
            'room missing' => [['room_id' => 999999], 'room_id'],
            'negative room' => [['room_id' => -1], 'room_id'],
            'array room' => [['room_id' => []], 'room_id'],
            'array title' => [['title' => ['bad']], 'title'],
            'long title' => [['title' => str_repeat('я', 256)], 'title'],
            'empty title' => [['title' => ' '], 'title'],
            'array date' => [['starts_at' => []], 'starts_at'],
            'missing offset' => [['starts_at' => '2026-11-10T10:00:00'], 'starts_at'],
            'impossible date' => [['starts_at' => '2026-02-30T10:00:00+03:00'], 'starts_at'],
            'relative date' => [['starts_at' => 'tomorrow'], 'starts_at'],
            'invalid hour' => [['starts_at' => '2026-11-10T25:00:00+03:00'], 'starts_at'],
            'short meeting' => [['ends_at' => '2026-11-10T10:05:00+03:00'], 'ends_at'],
            'long meeting' => [['ends_at' => '2026-11-10T14:15:00+03:00'], 'ends_at'],
            'start grid' => [['starts_at' => '2026-11-10T10:07:00+03:00'], 'starts_at'],
            'end grid' => [['ends_at' => '2026-11-10T11:07:00+03:00'], 'ends_at'],
            'seconds' => [['starts_at' => '2026-11-10T10:00:01+03:00'], 'starts_at'],
            'fraction' => [['ends_at' => '2026-11-10T11:00:00.000001+03:00'], 'ends_at'],
            'before opening' => [['starts_at' => '2026-11-10T08:45:00+03:00'], 'ends_at'],
            'after closing' => [['starts_at' => '2026-11-10T19:00:00+03:00', 'ends_at' => '2026-11-10T20:15:00+03:00'], 'ends_at'],
            'next day' => [['starts_at' => '2026-11-10T19:00:00+03:00', 'ends_at' => '2026-11-11T10:00:00+03:00'], 'ends_at'],
        ];
    }

    #[DataProvider('nonIncreasingEnds')]
    public function test_returns_clear_422_when_end_is_not_after_start(string $end): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payload = $this->payload(['ends_at' => $end]);

        $this->postJson('/api/bookings', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at' => 'Время окончания должно быть позже времени начала.']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public static function nonIncreasingEnds(): array
    {
        return [
            'earlier end' => ['2026-11-10T09:00:00+03:00'],
            'equal boundaries' => ['2026-11-10T10:00:00+03:00'],
            'same instant other offset' => ['2026-11-10T14:00:00+07:00'],
        ];
    }

    #[DataProvider('allowedIntervals')]
    public function test_accepts_working_hour_boundaries_and_any_offset(string $timezone, string $start, string $end): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->for(Office::factory()->state(['timezone' => $timezone]))->create();
        $payload = $this->payload(['room_id' => $room->id, 'starts_at' => $start, 'ends_at' => $end]);

        $this->postJson('/api/bookings', $payload)->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function allowedIntervals(): array
    {
        return [
            'minute precision' => ['Europe/Moscow', '2026-11-10T09:00+03:00', '2026-11-10T09:15+03:00'],
            '15 minutes at opening in UTC' => ['Europe/Moscow', '2026-11-10T06:00:00Z', '2026-11-10T06:15:00Z'],
            '4 hours ending at closing' => ['Europe/Moscow', '2026-11-10T16:00:00+03:00', '2026-11-10T20:00:00+03:00'],
            'Novosibirsk with Moscow offset' => ['Asia/Novosibirsk', '2026-11-10T05:00:00+03:00', '2026-11-10T06:00:00+03:00'],
            'different offsets for endpoints' => ['Europe/Moscow', '2026-11-10T10:00:00+03:00', '2026-11-10T15:00:00+07:00'],
            'negative offset and quarter hour' => ['Europe/Moscow', '2026-11-10T01:15:00-05:00', '2026-11-10T01:30:00-05:00'],
            'past date' => ['Europe/Moscow', '2020-01-01T10:00:00+03:00', '2020-01-01T11:00:00+03:00'],
            'future weekend' => ['Europe/Moscow', '2040-01-01T10:00:00+03:00', '2040-01-01T11:00:00+03:00'],
        ];
    }

    public function test_returns_422_outside_novosibirsk_office_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->for(Office::factory()->novosibirsk())->create();

        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $room->id, 'starts_at' => '2026-11-10T19:00:00+03:00', 'ends_at' => '2026-11-10T20:00:00+03:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    #[DataProvider('overlappingIntervals')]
    public function test_returns_422_for_overlap(string $start, string $end): void
    {
        Sanctum::actingAs(User::factory()->create());
        $booking = Booking::factory()->create(['starts_at' => '2026-11-10 07:00:00', 'ends_at' => '2026-11-10 08:00:00']);

        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $booking->room_id, 'starts_at' => $start, 'ends_at' => $end,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['starts_at' => 'Комната занята в выбранное время.']);

        $this->assertDatabaseCount('bookings', 1);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'same moment other offset' => ['2026-11-10T14:00:00+07:00', '2026-11-10T15:00:00+07:00'],
            'left overlap' => ['2026-11-10T09:30:00+03:00', '2026-11-10T10:30:00+03:00'],
            'right overlap' => ['2026-11-10T10:30:00+03:00', '2026-11-10T11:30:00+03:00'],
            'inside' => ['2026-11-10T10:15:00+03:00', '2026-11-10T10:45:00+03:00'],
            'contains' => ['2026-11-10T09:30:00+03:00', '2026-11-10T11:30:00+03:00'],
        ];
    }

    #[DataProvider('adjacentIntervals')]
    public function test_accepts_non_overlapping_meetings(string $start, string $end): void
    {
        Sanctum::actingAs(User::factory()->create());
        $booking = Booking::factory()->create(['starts_at' => '2026-11-10 07:00:00', 'ends_at' => '2026-11-10 08:00:00']);

        $this->postJson('/api/bookings', $this->payload([
            'room_id' => $booking->room_id, 'starts_at' => $start, 'ends_at' => $end,
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public static function adjacentIntervals(): array
    {
        return [
            'before' => ['2026-11-10T09:00:00+03:00', '2026-11-10T10:00:00+03:00'],
            'after' => ['2026-11-10T11:00:00+03:00', '2026-11-10T12:00:00+03:00'],
            'same clock different instant' => ['2026-11-10T10:00:00Z', '2026-11-10T11:00:00Z'],
        ];
    }

    public function test_allows_same_slot_in_another_room(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Booking::factory()->create(['starts_at' => '2026-11-10 07:00:00', 'ends_at' => '2026-11-10 08:00:00']);

        $this->postJson('/api/bookings', $this->payload())->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_only_author_can_delete_booking(): void
    {
        $booking = Booking::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/'.$booking->id)->assertForbidden();
        $this->assertModelExists($booking);

        Sanctum::actingAs($booking->user);
        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();
        $this->assertModelMissing($booking);
    }

    public function test_returns_404_for_missing_booking(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_returns_401_without_accept_header_or_token(): void
    {
        $this->get('/api/bookings')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->postJson('/api/bookings', [])->assertUnauthorized();
        $this->delete('/api/bookings/1')->assertUnauthorized();
        $this->get('/api/rooms/1/bookings/today')->assertUnauthorized();
        $this->withToken('invalid-token')->get('/api/bookings')->assertUnauthorized();
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_returns_validation_json_without_accept_header(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->post('/api/bookings', [])->assertUnprocessable()->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseCount('bookings', 0);
    }
}
