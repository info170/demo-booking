<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingConflictService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function __construct(private BookingConflictService $bookingConflictService) {}

    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request)
    {
        $validated = $request->validated();
        $validated['starts_at'] = Carbon::parse($validated['starts_at'])->utc()->format('Y-m-d H:i:s');
        $validated['ends_at'] = Carbon::parse($validated['ends_at'])->utc()->format('Y-m-d H:i:s');

        $booking = DB::transaction(function () use ($request, $validated): ?Booking {
            if ($this->bookingConflictService->hasConflict(
                $validated['room_id'],
                $validated['starts_at'],
                $validated['ends_at'],
                true,
            )) {
                return null;
            }

            return Booking::create([
                ...$validated,
                'user_id' => $request->user()->id,
            ]);
        });

        if ($booking === null) {
            return response()->json(['error' => 'Комната занята'], 409);
        }

        Log::withContext(['booking_id' => $booking->id]);
        Log::channel('bookings')->info('Booking created');

        return $booking;
    }

    public function destroy($id)
    {
        $booking = Booking::findOrFail($id);

        abort_unless($booking->user_id === request()->user()->id, 403, 'Удалить бронь может только её автор.');

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
