<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_requests_create_only_one_booking(): void
    {
        $room = Room::factory()->create();
        $token = User::factory()->create()->createToken('race-test')->plainTextToken;
        $payload = json_encode([
            'room_id' => $room->id, 'title' => 'Одновременная бронь',
            'starts_at' => '2026-11-10T10:00:00+03:00', 'ends_at' => '2026-11-10T11:00:00+03:00',
        ], JSON_THROW_ON_ERROR);
        $database = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'], 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'BOOKING_PAYLOAD' => $payload, 'BOOKING_TOKEN' => $token,
        ];
        $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$request = Illuminate\Http\Request::create('/api/bookings', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.getenv('BOOKING_TOKEN'),
], getenv('BOOKING_PAYLOAD'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
WORKER;
        $processes = [
            new Process([PHP_BINARY, '-r', $worker], base_path(), $environment, timeout: 20),
            new Process([PHP_BINARY, '-r', $worker], base_path(), $environment, timeout: 20),
        ];

        DB::beginTransaction();
        try {
            DB::table('rooms')->where('id', $room->id)->lockForUpdate()->first();
            foreach ($processes as $process) {
                $process->start();
            }

            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne("SELECT count(*) AS total FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query LIKE '%rooms%for update%'")->total;
                if ($waiting >= 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, implode("\n", array_map(fn (Process $process): string => $process->getErrorOutput().$process->getOutput(), $processes)));
            DB::commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $statuses[] = $process->getOutput();
            }
            sort($statuses);
            $this->assertSame(['201', '422'], $statuses);
            $this->assertDatabaseCount('bookings', 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }
}
