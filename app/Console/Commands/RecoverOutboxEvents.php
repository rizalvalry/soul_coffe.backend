<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverOutboxEvents extends Command
{
    protected $signature = 'outbox:recover {--limit=10 : Maximum rows to retry in this run}';

    protected $description = 'Retry unpublished outbox events after the recovery age';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('outbox.recovery_age_minutes')));
        $attempted = 0;
        $failed = 0;
        $limit = max(1, min(10, (int) $this->option('limit')));

        $stale = OutboxEvent::query()
            ->whereNull('published_at')
            ->where('created_at', '<=', $cutoff);
        $cursor = (int) Cache::get('outbox.recovery.cursor', 0);
        $ids = (clone $stale)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty() && $cursor > 0) {
            $ids = $stale->orderBy('id')->limit($limit)->pluck('id');
        }

        $ids->each(function (int $id) use (&$attempted, &$failed): void {
            $attempted++;
            try {
                (new PublishOutboxEvent($id))->handle();
            } catch (Throwable $e) {
                $failed++;
                Log::warning('broadcast: outbox recovery failed', [
                    'outbox_event_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        if ($ids->isNotEmpty()) {
            // Move past a repeatedly failing row so it cannot starve newer outbox rows.
            Cache::put('outbox.recovery.cursor', $ids->last(), now()->addDay());
        }

        $this->info('Dicoba pulihkan: '.$attempted);

        return $attempted > 0 && $failed === $attempted ? self::FAILURE : self::SUCCESS;
    }
}
