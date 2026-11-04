<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_non_overlapping_booking(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload($room, '11:15', '12:15'))
            ->assertCreated();
    }

    public function test_creates_booking_adjacent_to_existing_booking(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload($room, '11:00', '12:00'))
            ->assertCreated();
    }

    public function test_rejects_overlapping_booking(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload($room, '10:30', '11:30'))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Комната занята');
    }

    public function test_rejects_duplicate_slot_after_first_booking_is_committed(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        $payload = $this->payload($room, '10:00', '11:00');

        $this->postJson('/api/bookings', $payload)
            ->assertCreated();

        $this->postJson('/api/bookings', $payload)
            ->assertConflict()
            ->assertJsonPath('error', 'Комната занята');
    }

    #[DataProvider('invalidDurationProvider')]
    public function test_validates_booking_duration(string $startsAt, string $endsAt): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, $startsAt, $endsAt))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public static function invalidDurationProvider(): array
    {
        return [
            'less than 15 minutes' => ['10:00', '10:10'],
            'more than 4 hours' => ['10:00', '14:15'],
            'not a 15 minute step' => ['10:00', '10:20'],
        ];
    }

    public function test_requires_booking_to_fit_local_office_hours(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, '08:45', '09:45'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_requires_iso8601_datetime_with_timezone_offset(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        $payload = $this->payload($room, '10:00', '11:00');
        $payload['starts_at'] = '2026-11-02 10:00:00';
        $payload['ends_at'] = '2026-11-02 11:00:00';

        $this->postJson('/api/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'ends_at']);
    }

    public function test_accepts_iso8601_datetime_with_timezone_offset(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();
    }

    public function test_accepts_utc_iso8601_datetime(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T07:00:00Z',
            'ends_at' => '2026-11-02T08:00:00Z',
        ])->assertCreated();
    }

    public function test_accepts_minimum_duration(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, '09:00', '09:15'))
            ->assertCreated();
    }

    public function test_accepts_maximum_duration(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, '09:00', '13:00'))
            ->assertCreated();
    }

    public function test_accepts_booking_at_working_hours_boundaries(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, '19:00', '20:00'))
            ->assertCreated();
    }

    public function test_rejects_booking_ending_after_working_hours(): void
    {
        [$user, $room] = $this->authenticatedRoom();

        $this->postJson('/api/bookings', $this->payload($room, '19:15', '20:15'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_uses_authenticated_user_instead_of_request_user_id(): void
    {
        [$user, $room] = $this->authenticatedRoom();
        $otherUser = User::factory()->create();

        $this->postJson('/api/bookings', [
            ...$this->payload($room, '10:00', '11:00'),
            'user_id' => $otherUser->id,
        ])->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('bookings', [
            'room_id' => $room->id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_booking_author_can_cancel_booking(): void
    {
        $author = User::factory()->create();
        $room = Room::factory()->create();
        $booking = Booking::factory()->for($author)->for($room)->create();
        Sanctum::actingAs($author);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_checks_working_hours_in_office_timezone(): void
    {
        [$user, $room] = $this->authenticatedRoom(Office::factory()->novosibirsk()->create());

        $this->postJson('/api/bookings', [
            ...$this->payload($room, '04:00', '05:00'),
            'starts_at' => '2026-11-02T04:00:00+03:00',
            'ends_at' => '2026-11-02T05:00:00+03:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_only_booking_author_can_cancel_booking(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($otherUser);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    /** @return array{0: User, 1: Room} */
    private function authenticatedRoom(?Office $office = null): array
    {
        $user = User::factory()->create();
        $room = Room::factory()->for($office ?? Office::factory()->create())->create();
        Sanctum::actingAs($user);

        return [$user, $room];
    }

    /** @return array<string, mixed> */
    private function payload(Room $room, string $startsAt, string $endsAt): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => "2026-11-02T{$startsAt}:00+03:00",
            'ends_at' => "2026-11-02T{$endsAt}:00+03:00",
        ];
    }
}
