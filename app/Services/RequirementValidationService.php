<?php

namespace App\Services;

use App\Models\RequirementPreset;

/**
 * The single shared authority for checking passenger data against a trip's RequirementPreset.
 * Replaces the previously-dead check in CustomerBookingPortal::validatePassengers(), which
 * compared literal strings ('date_of_birth', 'document_number', 'passport_image') against
 * RequirementPreset::items — an array of {name, type, is_required} objects, never plain
 * strings, so the old `in_array()` calls were always false and nothing was ever enforced.
 *
 * Every caller (CheckoutWizard's strict pre-check, CreateBookingService's per-passenger
 * flag-setting, CustomerBookingPortal's post-upload recheck) goes through
 * findMissingRequirements() — the strict/permissive distinction lives entirely in what each
 * caller does with the result (block vs. tag-and-notify), not in a second implementation.
 */
class RequirementValidationService
{
    /**
     * @param array<int, array{
     *     document_number?: ?string,
     *     date_of_birth?: ?string,
     *     has_identity_document?: bool,
     * }> $passengersData Keyed by the same index the caller wants back in the result.
     * @return array<int, array{passenger_index: int, type: string, label: string}> Empty if
     *     every required item is satisfied for every passenger (including when there is no
     *     preset attached at all, or the preset has zero required items).
     */
    public function findMissingRequirements(?RequirementPreset $preset, array $passengersData): array
    {
        if (!$preset) {
            return [];
        }

        $missing = [];

        foreach ($preset->items ?? [] as $item) {
            if (!($item['is_required'] ?? false)) {
                continue;
            }

            $type = $item['type'] ?? 'text';

            foreach ($passengersData as $index => $passenger) {
                $satisfied = match ($type) {
                    'date' => !empty($passenger['date_of_birth'] ?? null),
                    'image' => !empty($passenger['has_identity_document'] ?? false),
                    default => !empty($passenger['document_number'] ?? null), // 'text'
                };

                if (!$satisfied) {
                    $missing[] = [
                        'passenger_index' => $index,
                        'type' => $type,
                        'label' => $item['name'] ?? $type,
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * Text/date items only — the subset that CAN be enforced at booking-time form submission
     * today (no entry point has a document-image upload widget in its create flow; only the
     * post-booking CustomerBookingPortal does). This is what a strict caller should block on.
     */
    public function blockingMisses(array $missing): array
    {
        return array_values(array_filter($missing, fn (array $m) => in_array($m['type'], ['text', 'date'], true)));
    }

    /**
     * Whether the passenger at $index has zero missing items of ANY type (text, date, or
     * image). Used to set Passenger::requirements_complete — a passenger can pass strict
     * checkout validation (text/date satisfied) and still be incomplete here if an image item
     * is outstanding.
     */
    public function isPassengerComplete(array $missing, int $index): bool
    {
        foreach ($missing as $m) {
            if ($m['passenger_index'] === $index) {
                return false;
            }
        }

        return true;
    }

    /**
     * Human-readable summary of which of a just-created booking's passengers still have
     * Passenger::requirements_complete = false, for the permissive entry points
     * (QuickBookingPage/PhoneBookingPage/admin Create Booking) to surface as a warning
     * notification. Returns null when everyone is complete (no notification needed). Only
     * builds the message — presentation (Notification::make()) stays with each caller, matching
     * how every other service in this app (InventoryService, BookingService, ...) leaves
     * UI/notification decisions to the Livewire/Filament layer rather than making them itself.
     */
    public function summarizeIncompletePassengers(\App\Models\Booking $booking): ?string
    {
        $incomplete = $booking->passengers()->where('requirements_complete', false)->get();

        if ($incomplete->isEmpty()) {
            return null;
        }

        $names = $incomplete->map(fn ($p) => $p->display_name)->implode('، ');

        return "الركاب التاليون تنقصهم بيانات أو مستندات مطلوبة لهذه الرحلة: {$names}. يمكن إكمالها لاحقاً عبر رابط العميل أو من صفحة الحجز.";
    }
}
