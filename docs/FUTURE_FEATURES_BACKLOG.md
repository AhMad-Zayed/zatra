# Future Features / Known Gaps Backlog

Small, real, non-urgent gaps discovered during other work. Logged here instead of fixed inline
when the fix needs more than a one-liner (e.g. a migration), so they aren't lost but also don't
scope-creep the ticket that found them.

## `PhoneBookingPage::submitWaitlist()` silently drops the `notes` field

**Found**: 2026-08-27, during Waitlist-to-Different-Trip Manual Transfer Phase 0 investigation.

`app/Filament/Pages/PhoneBookingPage.php`'s `submitWaitlist()` passes `'notes' => $this->waitlistNotes ?: '...'`
into `WaitingList::create([...])`, but `notes` is neither in `WaitingList::$fillable` nor an
actual column on the `waiting_lists` table. Mass-assignment protection silently drops it before
the insert — no error, no data loss visible to staff, the note just never gets saved anywhere.

**Fix needed**: a real migration adding a `notes` (nullable text) column to `waiting_lists`, plus
adding it to `WaitingList::$fillable`. Not a one-line fix, so deferred rather than folded into
whichever ticket happens to touch this file next.
