<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\Carbon;

class BookingConflictService
{
    public function hasConflict(int $roomId, string $startsAt, string $endsAt): bool
    {
        $firstList = Booking::query()
            ->where('room_id', $roomId)
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Booking $booking): array => [
                $this->databaseTimestamp($booking->starts_at->format('Y-m-d H:i:s')),
                $this->databaseTimestamp($booking->ends_at->format('Y-m-d H:i:s')),
            ])
            ->all();

        $secondList = [[
            $this->databaseTimestamp($startsAt),
            $this->databaseTimestamp($endsAt),
        ]];

        return $this->intervalIntersection($firstList, $secondList) !== [];
    }

    /**
     * @param array<int, array{0: int, 1: int}> $firstList
     * @param array<int, array{0: int, 1: int}> $secondList
     * @return array<int, array{0: int, 1: int}>
     */
    public function intervalIntersection(array $firstList, array $secondList): array
    {
        if ($firstList === [] || $secondList === []) {
            return [];
        }

        $cross = [];
        $i = 0;
        $j = 0;
        $n = count($firstList);
        $m = count($secondList);

        while ($i < $n && $j < $m) {
            $lo = max($firstList[$i][0], $secondList[$j][0]);
            $hi = min($firstList[$i][1], $secondList[$j][1]);

            if ($lo < $hi) {
                $cross[] = [$lo, $hi];
            }

            if ($firstList[$i][1] < $secondList[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $cross;
    }

    private function databaseTimestamp(string $value): int
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            Carbon::parse($value)->format('Y-m-d H:i:s'),
            'UTC',
        )->timestamp;
    }
}
