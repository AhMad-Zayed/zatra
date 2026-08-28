<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\RelationManagers\PassengersRelationManager as BookingPassengersRelationManager;
use App\Filament\Resources\BookingResource\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\BookingsRelationManager as CustomerBookingsRelationManager;
use App\Filament\Resources\TripInstanceResource\RelationManagers\BookingsRelationManager as TripInstanceBookingsRelationManager;
use App\Filament\Resources\TripInstanceResource\RelationManagers\PackageOptionsRelationManager;
use App\Filament\Resources\TripInstanceResource\RelationManagers\TripPassengersRelationManager;
use App\Filament\Resources\TripInstanceResource\RelationManagers\TripStayLegsRelationManager;
use App\Filament\Resources\TripInstanceResource\RelationManagers\WaitingListsRelationManager;
use App\Filament\Resources\TripTemplateResource\RelationManagers\TripInstancesRelationManager;
use Tests\TestCase;

/**
 * Admin panel UX audit, Friction Point #5: none of the 9 relation managers in the admin panel
 * overrode getModelLabel()/getPluralModelLabel(), so Filament fell back to guessing an English
 * label from the PHP class name for any modal title, empty-state heading, or save notification
 * that needed one -- confirmed live as "payment إضافة", "لا توجد payments", "لا توجد trip stay
 * legs". This is a static sweep: every relation manager in the app must return a real Arabic
 * label with no Latin-alphabet fallback, rather than spot-checking the handful the audit
 * happened to catch live.
 */
class RelationManagerArabicLabelsTest extends TestCase
{
    /**
     * @return array<int, array{0: class-string}>
     */
    public static function relationManagers(): array
    {
        return [
            [BookingPassengersRelationManager::class],
            [PaymentsRelationManager::class],
            [CustomerBookingsRelationManager::class],
            [TripInstanceBookingsRelationManager::class],
            [PackageOptionsRelationManager::class],
            [TripPassengersRelationManager::class],
            [TripStayLegsRelationManager::class],
            [WaitingListsRelationManager::class],
            [TripInstancesRelationManager::class],
        ];
    }

    /**
     * @dataProvider relationManagers
     */
    public function test_relation_manager_has_no_english_fallback_label(string $class): void
    {
        $modelLabel = $class::getModelLabel();
        $pluralModelLabel = $class::getPluralModelLabel();

        $this->assertNotEmpty($modelLabel, "{$class}::getModelLabel() must not be empty.");
        $this->assertNotEmpty($pluralModelLabel, "{$class}::getPluralModelLabel() must not be empty.");

        // No Latin letters at all -- catches both a raw English word (Filament's class-name
        // guess, e.g. "Payment") and a half-Arabic/half-English mix (e.g. "payment إضافة").
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Za-z]/',
            $modelLabel,
            "{$class}::getModelLabel() ('{$modelLabel}') must not contain any Latin characters."
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Za-z]/',
            $pluralModelLabel,
            "{$class}::getPluralModelLabel() ('{$pluralModelLabel}') must not contain any Latin characters."
        );
    }
}
