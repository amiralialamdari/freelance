<?php

namespace App\Services;

class ProjectFilter
{
    /**
     * Return null when the project should be analysed, or a short skip reason.
     */
    public function rejectReason(array $project): ?string
    {
        $jobs = $project['jobs'];

        foreach ($jobs as $job) {
            foreach (config('freelance.excluded_job_keywords') as $keyword) {
                if (str_contains(strtolower($job['name']), $keyword)) {
                    return "excluded skill: {$job['name']}";
                }
            }
        }

        $programming = array_filter($jobs, fn ($job) => $this->isProgrammingJob($job));
        if ($programming === []) {
            return 'not a programming project';
        }

        $country = $project['client']['country'] ?? null;
        if ($country !== null && in_array($country, config('freelance.excluded_countries'), true)) {
            return "excluded country: {$country}";
        }

        $min = (float) config('freelance.min_budget_usd');
        if ($min > 0 && $project['budget_max_usd'] !== null && $project['budget_max_usd'] < $min) {
            return "budget below {$min} USD";
        }

        return null;
    }

    private function isProgrammingJob(array $job): bool
    {
        if (! in_array($job['category_id'], config('freelance.categories'), true)) {
            return false;
        }

        $name = strtolower($job['name']);

        // e.g. "AI Art Creation" or "Website Design" are not programming work.
        if ($this->matchesAny($name, config('freelance.non_programming_job_keywords'))) {
            return false;
        }

        return $this->matchesAny($name, config('freelance.programming_job_keywords'));
    }

    private function matchesAny(string $name, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            // Custom boundaries so keywords like "c#", "c++" and ".net" match as whole words.
            if (preg_match('/(?<![a-z0-9])'.preg_quote($keyword, '/').'(?![a-z0-9])/', $name)) {
                return true;
            }
        }

        return false;
    }
}
