<?php

namespace App\Services;

use App\Dto\CreateBookingDto;
use App\Models\Booking;
use App\Models\Room;
use App\Repositories\BookingRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private readonly BookingRepository $bookings) {}

    public function create(CreateBookingDto $data): Booking
    {
        $booking = $this->bookings->withLockedRoom($data->roomId, function (?Room $room) use ($data): Booking {
            if ($room === null) {
                throw ValidationException::withMessages(['room_id' => 'Комната не найдена.']);
            }

            $this->validateInterval($data, $room->office->timezone);

            if ($this->bookings->hasConflict($data)) {
                throw ValidationException::withMessages(['starts_at' => 'Комната занята в выбранное время.']);
            }

            return $this->bookings->create($data);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return $booking;
    }

    private function validateInterval(CreateBookingDto $data, string $timezone): void
    {
        $durationMinutes = $data->startsAt->diffInMinutes($data->endsAt);
        if ($durationMinutes <= 0) {
            throw ValidationException::withMessages(['ends_at' => 'Время окончания должно быть позже времени начала.']);
        }

        if ($durationMinutes < 15 || $durationMinutes > 240) {
            throw ValidationException::withMessages(['ends_at' => 'Длительность должна быть от 15 минут до 4 часов.']);
        }

        $start = $data->startsAt->setTimezone($timezone);
        $end = $data->endsAt->setTimezone($timezone);
        foreach (['starts_at' => $start, 'ends_at' => $end] as $field => $date) {
            if ($date->minute % 15 !== 0 || $date->second !== 0 || $date->microsecond !== 0) {
                throw ValidationException::withMessages([$field => 'Время должно быть кратно 15 минутам, без секунд и дробей.']);
            }
        }

        if ($start->toDateString() !== $end->toDateString() || $start->hour < 9 || $end->greaterThan($start->setTime(20, 0))) {
            throw ValidationException::withMessages(['ends_at' => 'Бронь должна укладываться в 09:00–20:00 одного дня по времени офиса.']);
        }
    }

    public function delete(int $id, int $userId): void
    {
        $booking = $this->bookings->find($id);
        if ($booking->user_id !== $userId) {
            throw new AuthorizationException('Отменить бронь может только её автор.');
        }

        $this->bookings->delete($booking);
    }
}
