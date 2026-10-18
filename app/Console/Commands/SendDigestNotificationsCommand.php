<?php

namespace App\Console\Commands;

use App\Enums\NotificationModeEnum;
use App\Models\NotificationDigestItem;
use App\Models\User;
use App\Notifications\DigestNotification;
use App\Notifications\EmptyDigestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('notifications:send-digest')]
#[Description('Send daily digest notifications to users who are due')]
class SendDigestNotificationsCommand extends Command
{
    public function handle(): int
    {
        // A blocked guard UPDATE (a stuck row lock) would otherwise silently ride MySQL's default
        // innodb_lock_wait_timeout (~50s), indistinguishable from the
        // CronJob's own activeDeadlineSeconds killing the pod - no error
        // logged either way. A short session-level timeout makes a stuck
        // lock fail fast with a clear, catchable exception instead. MySQL
        // only - sqlite (used in tests) has no such session variable.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        }

        $now = CarbonImmutable::now('UTC');

        $users = User::query()
            ->whereJsonContains('settings->notification_mode', NotificationModeEnum::DailyDigest->value)
            ->where(function ($q) use ($now): void {
                $q->whereNull('last_digest_sent_at')
                    ->orWhereDate('last_digest_sent_at', '<', $now->toDateString());
            })
            ->get();

        Log::debug('SendDigestNotifications: checking users', ['count' => $users->count()]);

        foreach ($users as $user) {
            try {
                $this->processUser($user, $now);
            } catch (\Throwable $e) {
                Log::error('SendDigestNotifications: failed to process user, will retry next tick', [
                    'user_id' => $user->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function processUser(User $user, CarbonImmutable $now): void
    {
        $userTimezone = $user->timezone();
        $userNow = $now->setTimezone($userTimezone);
        $digestTime = $user->digestTime();

        [$digestHour, $digestMinute] = array_map(intval(...), explode(':', $digestTime));

        $digestDueAt = $userNow->setTime($digestHour, $digestMinute);

        if ($userNow->lt($digestDueAt)) {
            Log::debug('SendDigestNotifications: user not yet at digest time', [
                'user_id' => $user->id,
                'digest_time' => $digestTime,
                'user_tz' => $userTimezone,
            ]);

            return;
        }

        $items = NotificationDigestItem::query()
            ->where('user_id', $user->id)
            ->with('project')
            ->get();

        // Claim the guard before sending, not after: if the process dies mid-send
        // (SMTP hiccup, DB deadlock, the scheduler CronJob's activeDeadlineSeconds
        // killing the run) with the guard written last, the next tick sees the user
        // as still due and resends the same mail. Claiming first means a mid-send
        // failure costs an undelivered digest (its items just roll into tomorrow's
        // run) instead of a duplicate one.
        $user->update(['last_digest_sent_at' => now()]);

        if ($items->isEmpty()) {
            if ($user->notifyWhenEmpty()) {
                $user->notify(new EmptyDigestNotification);

                Log::info('SendDigestNotifications: empty digest sent', [
                    'user_id' => $user->id,
                ]);

                return;
            }

            Log::info('SendDigestNotifications: digest due but no items, skipping send', [
                'user_id' => $user->id,
            ]);

            return;
        }

        $user->notify(new DigestNotification($items));
        NotificationDigestItem::where('user_id', $user->id)->delete();

        Log::info('SendDigestNotifications: digest sent', [
            'user_id' => $user->id,
            'item_count' => $items->count(),
        ]);
    }
}
