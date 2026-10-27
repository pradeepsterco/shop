<?php

namespace App\Content\Commands;

use App\Content\Enums\PageStatus;
use App\Content\Models\ContentPage;
use App\Content\Services\PagePublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PublishScheduledPagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'content:publish-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish content pages whose scheduled publication timestamp is due';

    /**
     * Execute the console command.
     */
    public function handle(PagePublishService $publishService): int
    {
        $now = Carbon::now();

        $duePages = ContentPage::query()
            ->where('status', PageStatus::Scheduled)
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', $now)
            ->get();

        if ($duePages->isEmpty()) {
            $this->info('No scheduled pages due for publication.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($duePages as $page) {
            $publishService->publish($page);
            $this->info("Published scheduled page: '{$page->title}' (slug: {$page->slug})");
            $count++;
        }

        $this->info("Successfully published {$count} scheduled page(s).");

        return self::SUCCESS;
    }
}
