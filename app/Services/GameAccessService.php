<?php

namespace App\Services;

use App\Events\TablePlayEvent;
use App\Models\GameSession;
use App\Models\Order;
use App\Models\RestaurantSetting;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameAccessService
{
    public function restartFor(Order $order): ?GameSession
    {
        // Order confirmation is a core restaurant operation. A licence change
        // racing with confirmation must skip the optional timer, never roll the
        // order back or leave it pending.
        if (! app(EntitlementService::class)->allows('games')) {
            return null;
        }

        return DB::transaction(function () use ($order) {
            $tableSession = TableSession::lockForUpdate()->findOrFail($order->table_session_id);

            if ($tableSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'table_session' => 'The table session is no longer open.',
                ]);
            }

            GameSession::query()
                ->where('table_session_id', $tableSession->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'expired',
                    'stopped_at' => now(),
                ]);

            $minutes = max(1, (int) (RestaurantSetting::value('game_duration_minutes') ?? 60));
            $gameSession = GameSession::create([
                'table_session_id' => $tableSession->id,
                'trigger_order_id' => $order->id,
                'started_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
                'status' => 'active',
            ]);

            $tableId = $tableSession->dining_table_id;
            DB::afterCommit(fn () => TablePlayEvent::dispatch(
                'GameSessionStarted',
                ['game_session' => $gameSession->toArray()],
                ['table.'.$tableId, 'counter'],
            ));

            return $gameSession;
        });
    }

    public function extend(GameSession $gameSession, int $minutes): GameSession
    {
        app(EntitlementService::class)->assertFeature('games');

        $extended = DB::transaction(function () use ($gameSession, $minutes) {
            $locked = GameSession::with('tableSession')
                ->lockForUpdate()
                ->findOrFail($gameSession->id);

            if (
                $locked->status !== 'active'
                || $locked->expires_at->isPast()
                || $locked->tableSession->status !== 'open'
            ) {
                if ($locked->status === 'active' && $locked->expires_at->isPast()) {
                    $locked->update(['status' => 'expired', 'stopped_at' => now()]);
                }

                return null;
            }

            $locked->update(['expires_at' => $locked->expires_at->copy()->addMinutes($minutes)]);
            $locked->refresh();

            DB::afterCommit(fn () => TablePlayEvent::dispatch(
                'GameSessionStarted',
                [
                    'game_session' => $locked->toArray(),
                    'extended_minutes' => $minutes,
                ],
                ['table.'.$locked->tableSession->dining_table_id, 'counter'],
            ));

            return $locked;
        });

        if (! $extended) {
            throw ValidationException::withMessages([
                'game_session' => 'This game timer has already ended and cannot be extended.',
            ]);
        }

        return $extended;
    }

    public function stop(GameSession $gameSession, ?int $userId = null): GameSession
    {
        return DB::transaction(function () use ($gameSession, $userId) {
            $locked = GameSession::with('tableSession')
                ->lockForUpdate()
                ->findOrFail($gameSession->id);

            if ($locked->status !== 'active') {
                throw ValidationException::withMessages([
                    'game_session' => 'This game timer has already ended.',
                ]);
            }

            $locked->update([
                'status' => $locked->expires_at->isPast() ? 'expired' : 'stopped',
                'stopped_at' => now(),
                'stopped_by' => $userId,
            ]);
            $locked->refresh();

            DB::afterCommit(fn () => TablePlayEvent::dispatch(
                'GameSessionStopped',
                ['game_session' => $locked->toArray()],
                ['table.'.$locked->tableSession->dining_table_id, 'counter'],
            ));

            return $locked;
        });
    }

    public function state(TableSession $tableSession): array
    {
        if (! app(EntitlementService::class)->allows('games')) {
            return $this->lockedState('plan');
        }

        $active = $tableSession->status === 'open'
            ? GameSession::query()
                ->where('table_session_id', $tableSession->id)
                ->where('status', 'active')
                ->latest('id')
                ->first()
            : null;

        if ($active && $active->expires_at->isPast()) {
            $active->update(['status' => 'expired', 'stopped_at' => now()]);
            $active = null;
        }

        return [
            'unlocked' => (bool) $active,
            'server_time' => now()->toIso8601String(),
            'expires_at' => $active?->expires_at?->toIso8601String(),
            'remaining_seconds' => $active
                ? (int) max(0, ceil(now()->diffInSeconds($active->expires_at, false)))
                : 0,
            'session' => $active,
        ];
    }

    private function lockedState(string $reason): array
    {
        return [
            'unlocked' => false,
            'server_time' => now()->toIso8601String(),
            'expires_at' => null,
            'remaining_seconds' => 0,
            'session' => null,
            'reason' => $reason,
        ];
    }

    public function stopAll(TableSession $tableSession, ?int $userId = null): void
    {
        $stopped = GameSession::query()
            ->where('table_session_id', $tableSession->id)
            ->where('status', 'active')
            ->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stopped_by' => $userId,
            ]);

        if ($stopped) {
            DB::afterCommit(fn () => TablePlayEvent::dispatch(
                'GameSessionStopped',
                ['table_session_id' => $tableSession->id],
                ['table.'.$tableSession->dining_table_id, 'counter'],
            ));
        }
    }
}
