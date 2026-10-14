<?php

namespace App\Console\Commands;

use App\Models\FreelanceProject;
use App\Services\FreelancerApi;
use App\Services\ProjectFilter;
use App\Services\ProposalWriter;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanFreelancer extends Command
{
    protected $signature = 'freelancer:scan
        {--dry : Analyse but print to the console instead of sending to Telegram}
        {--seed=3 : On the very first run, only analyse this many of the newest projects}';

    protected $description = 'Fetch new Freelancer.com projects, analyse them with Claude and send them to Telegram';

    public function handle(FreelancerApi $api, ProjectFilter $filter, ProposalWriter $writer, TelegramNotifier $telegram): int
    {
        $dry = (bool) $this->option('dry');

        if (! config('freelance.anthropic_key') || (! $dry && (! config('freelance.telegram_token') || ! config('freelance.telegram_chat_id')))) {
            $this->error('Set ANTHROPIC_API_KEY, TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in .env first.');

            return self::FAILURE;
        }

        $this->collect($api, $filter);
        $this->expireOld();

        $pending = FreelanceProject::where('status', 'pending')
            ->orderByDesc('submitted_at')
            ->limit(config('freelance.max_per_scan'))
            ->get();

        foreach ($pending as $project) {
            $this->process($project, $writer, $telegram, $dry);
        }

        return self::SUCCESS;
    }

    private function collect(FreelancerApi $api, ProjectFilter $filter): void
    {
        $firstRun = FreelanceProject::count() === 0;
        $projects = $api->latestProjects();
        $known = FreelanceProject::whereIn('freelancer_id', array_column($projects, 'id'))->pluck('freelancer_id')->all();

        $new = 0;
        foreach ($projects as $p) {
            if (in_array($p['id'], $known)) {
                continue;
            }

            $reason = $filter->rejectReason($p);
            FreelanceProject::create([
                'freelancer_id' => $p['id'],
                'title' => mb_substr($p['title'], 0, 250),
                'url' => $p['url'],
                'status' => $reason ? 'skipped' : 'pending',
                'skip_reason' => $reason,
                'payload' => $p,
                'submitted_at' => $p['submitted_at'] ? now()->setTimestamp($p['submitted_at']) : now(),
            ]);
            $reason ?: $new++;
        }

        // Don't flood Telegram with the whole backlog on the first run.
        if ($firstRun) {
            $keep = FreelanceProject::where('status', 'pending')->orderByDesc('submitted_at')->limit((int) $this->option('seed'))->pluck('id');
            FreelanceProject::where('status', 'pending')->whereNotIn('id', $keep)->update(['status' => 'skipped', 'skip_reason' => 'initial backlog']);
            $new = $keep->count();
        }

        $this->info('Fetched '.count($projects)." projects, {$new} new to analyse.");
    }

    private function expireOld(): void
    {
        FreelanceProject::where('status', 'pending')
            ->where('submitted_at', '<', now()->subMinutes(config('freelance.max_age_minutes')))
            ->update(['status' => 'expired']);
    }

    private function process(FreelanceProject $project, ProposalWriter $writer, TelegramNotifier $telegram, bool $dry): void
    {
        $this->line("→ {$project->title}");

        try {
            if (! $project->analysis) {
                $analysis = $writer->analyse($project->payload);
                $project->update(['analysis' => $analysis, 'score' => max(0, min(10, (int) $analysis['score']))]);
            }

            $analysis = $project->analysis;

            if (! $analysis['relevant']) {
                $project->update(['status' => 'irrelevant', 'skip_reason' => mb_substr($analysis['relevance_note_fa'], 0, 250)]);
                $this->line('  not relevant');

                return;
            }

            if ($project->score < config('freelance.min_score')) {
                $project->update(['status' => 'skipped', 'skip_reason' => "score {$project->score}"]);
                $this->line("  score {$project->score}, below minimum");

                return;
            }

            if ($dry) {
                $this->line("  score {$project->score}, bid {$analysis['bid_amount']} {$project->payload['currency']}, {$analysis['delivery_days']} days");
                $this->line($analysis['summary_fa']);
                $this->newLine();
                $this->line($analysis['proposal_en']);
                $this->newLine();

                return;
            }

            $telegram->sendProject($project);
            $project->update(['status' => 'sent', 'sent_at' => now()]);
            $this->line("  sent (score {$project->score})");
        } catch (Throwable $e) {
            $attempts = $project->attempts + 1;
            $project->update(['attempts' => $attempts, 'status' => $attempts >= 3 ? 'failed' : 'pending']);
            Log::error('Failed to process Freelancer project', ['id' => $project->freelancer_id, 'error' => $e->getMessage()]);
            $this->error('  failed: '.$e->getMessage());
        }
    }
}
