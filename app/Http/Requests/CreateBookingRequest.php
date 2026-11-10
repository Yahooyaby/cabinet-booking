<?php

namespace App\Http\Requests;

use App\Dto\CreateBookingDto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class CreateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $dateRules = [
            'bail',
            'required',
            'string',
            'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
            'date'
        ];

        return [
            'room_id' => ['bail', 'required', 'integer', 'min:1'],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'starts_at' => $dateRules,
            'ends_at' => $dateRules,
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Поле :attribute обязательно.',
            'string' => 'Поле :attribute должно быть строкой.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.min' => 'Идентификатор комнаты должен быть положительным.',
            'title.max' => 'Название должно содержать не более 255 символов.',
            'starts_at.regex' => 'Укажите начало в ISO 8601 со смещением или Z.',
            'ends_at.regex' => 'Укажите конец в ISO 8601 со смещением или Z.',
            'date' => 'Поле :attribute должно содержать существующую дату.',
        ];
    }

    public function toDto(): CreateBookingDto
    {
        $data = $this->validated();

        return new CreateBookingDto(
            roomId: (int) $data['room_id'],
            userId: (int) $this->user()->id,
            title: $data['title'],
            startsAt: CarbonImmutable::parse($data['starts_at'])->utc(),
            endsAt: CarbonImmutable::parse($data['ends_at'])->utc(),
        );
    }
}
