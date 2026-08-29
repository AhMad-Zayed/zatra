<?php

namespace App\Filament\Resources\TripInstanceResource\Pages;

use App\Filament\Resources\TripInstanceResource;
use App\Jobs\BulkGenerateTripInstances;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Ported from the retired TripBuilderResource (deleted — unmaintained since 2026-08-10, and its
 * form never collected trip_type at all, silently leaving every template it created NULL): the
 * recurring-schedule bulk-generation capability, as an additional option alongside the existing
 * single-instance create rather than a replacement for it.
 *
 * trip_type itself needs no special handling here — it lives on TripTemplate, not TripInstance
 * (confirmed elsewhere in this resource: the table reads it through the tripTemplate relation),
 * and recurring mode always generates instances against an EXISTING template selected via the
 * same trip_template_id field single-mode already uses. Whatever trip_type that template already
 * has (set correctly via TripTemplateResource's own form) is simply inherited by every instance
 * the same way it always is — nothing to port for that part.
 */
class CreateTripInstance extends CreateRecord
{
    protected static string $resource = TripInstanceResource::class;

    protected bool $isRecurringSchedule = false;

    /** @var array<string, mixed> */
    protected array $recurringScheduleData = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->isRecurringSchedule = ($data['schedule_type'] ?? 'single') === 'recurring';

        if ($this->isRecurringSchedule) {
            $this->recurringScheduleData = [
                'seats_count' => (int) ($data['recurring_seats_count'] ?? 0),
                'start' => $data['recurring_start'] ?? null,
                'end' => $data['recurring_end'] ?? null,
                'days' => array_map('intval', $data['recurring_days'] ?? []),
                'pickup_route_ids' => $data['recurring_pickup_routes'] ?? [],
            ];
        }

        unset(
            $data['schedule_type'],
            $data['recurring_seats_count'],
            $data['recurring_start'],
            $data['recurring_end'],
            $data['recurring_days'],
            $data['recurring_pickup_routes'],
        );

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->isRecurringSchedule) {
            return parent::handleRecordCreation($data);
        }

        $dates = [];
        $cursor = Carbon::parse($this->recurringScheduleData['start']);
        $end = Carbon::parse($this->recurringScheduleData['end']);
        $allowedDays = $this->recurringScheduleData['days'];

        while ($cursor->lte($end)) {
            if (in_array($cursor->dayOfWeek, $allowedDays, true)) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        if (empty($dates)) {
            Notification::make()
                ->title('لا توجد تواريخ مطابقة')
                ->body('لم يتم العثور على أي تاريخ يطابق الأيام المحددة ضمن النطاق الزمني المُختار — لم يتم إنشاء أي موعد.')
                ->danger()
                ->send();

            throw new Halt();
        }

        // Calls handle() directly rather than dispatch()/dispatchSync(): bulk generation only
        // ever needs to run once, synchronously, as part of this same request — the admin is
        // waiting to see the result immediately, and this avoids depending on a queue worker
        // actually being active. BulkGenerateTripInstances keeps implementing ShouldQueue for any
        // future caller that *does* want it queued (none do today), but Laravel's queue dispatch
        // path (even dispatchSync()) runs a ShouldQueue job through SyncQueue::push(), which
        // returns its own job-id placeholder rather than propagating handle()'s return value —
        // calling handle() directly is what actually hands back the created instances here.
        $createdInstances = (new BulkGenerateTripInstances(
            (int) $data['trip_template_id'],
            $dates,
            $this->recurringScheduleData['seats_count'],
            $this->recurringScheduleData['pickup_route_ids'],
            auth()->id(),
        ))->handle();

        if ($createdInstances->isEmpty()) {
            Notification::make()
                ->title('تعذر إنشاء المواعيد')
                ->body('لم يتم العثور على البرنامج السياحي المحدد.')
                ->danger()
                ->send();

            throw new Halt();
        }

        // Filament redirects to/edits whatever this method returns; the first generated instance
        // is as good a landing page as any single one among an equally-valid batch.
        return $createdInstances->first();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        if (! $this->isRecurringSchedule) {
            return parent::getCreatedNotificationTitle();
        }

        // BulkGenerateTripInstances already sends its own "N مواعيد تم إنشاؤها" database
        // notification (unchanged from its original TripBuilderResource caller) — the generic
        // single-record "created" toast would be misleading here (it implies one record), so
        // suppress it rather than show a redundant, inaccurate message.
        return null;
    }
}
