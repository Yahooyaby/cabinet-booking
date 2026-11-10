<?php

namespace App\Dto;

use Carbon\CarbonImmutable;

final readonly class CreateBookingDto
{
    public function __construct(
        public int $roomId,
        public int $userId,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}
}
