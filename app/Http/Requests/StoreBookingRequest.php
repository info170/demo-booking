<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $room = Room::with('office')->find($this->integer('room_id'));
            $startsAt = Carbon::parse($this->input('starts_at'));
            $endsAt = Carbon::parse($this->input('ends_at'));
            $duration = $startsAt->diffInMinutes($endsAt);
            $localStart = $startsAt->setTimezone($room->office->timezone);
            $localEnd = $endsAt->setTimezone($room->office->timezone);

            if ($duration < 15 || $duration > 240 || $duration % 15 !== 0) {
                $validator->errors()->add(
                    'ends_at',
                    'Длительность бронирования должна быть от 15 минут до 4 часов с шагом 15 минут.',
                );
            }

            if ($localStart->toDateString() !== $localEnd->toDateString()
                || $localStart->format('H:i') < '09:00'
                || $localEnd->format('H:i') > '20:00'
            ) {
                $validator->errors()->add(
                    'starts_at',
                    'Бронирование должно полностью находиться в рабочем времени офиса: с 09:00 до 20:00.',
                );
            }
        });
    }
}
