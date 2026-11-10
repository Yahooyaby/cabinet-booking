<?php

namespace App\Repositories;

use App\Dto\CreateBookingDto;
use App\Models\Booking;
use App\Models\Room;
use Closure;
use Illuminate\Support\Facades\DB;

class BookingRepository
{
    /** @param Closure(?Room): Booking $create */
    public function withLockedRoom(int $roomId, Closure $create): Booking
    {
        return DB::transaction(function () use ($roomId, $create): Booking {
            $room = Room::query()->lockForUpdate()->find($roomId);
            $room?->load('office');

            return $create($room);
        }, 3);
    }

    public function hasConflict(CreateBookingDto $data): bool
    {
        return Booking::where('room_id', $data->roomId)
            ->where('starts_at', '<', $data->endsAt)
            ->where('ends_at', '>', $data->startsAt)->exists();
    }

    public function create(CreateBookingDto $data): Booking
    {
        return Booking::create([
            'room_id' => $data->roomId,
            'user_id' => $data->userId,
            'title' => $data->title,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
        ]);
    }

    public function find(int $id): Booking
    {
        return Booking::findOrFail($id);
    }

    public function delete(Booking $booking): void
    {
        $booking->delete();
    }
}
