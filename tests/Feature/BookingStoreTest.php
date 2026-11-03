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
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->postJson('/api/bookings', $this->payload($room, '10:30', '11:30'))
            ->assertStatus(409)
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
