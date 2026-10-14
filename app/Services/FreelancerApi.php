<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class FreelancerApi
{
    /**
     * Fetch the newest active projects, normalised to flat arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function latestProjects(): array
    {
        $response = $this->http()->get('/projects/0.1/projects/active/', [
            'limit' => config('freelance.fetch_limit'),
            'sort_field' => 'time_submitted',
            'full_description' => 'true',
            'job_details' => 'true',
            'user_details' => 'true',
            'user_country_details' => 'true',
            'user_reputation' => 'true',
            'user_status' => 'true',
        ])->throw()->json('result');

        $users = $response['users'] ?? [];

        return array_map(
            fn (array $project) => $this->normalise($project, $users[$project['owner_id'] ?? ''] ?? null),
            $response['projects'] ?? [],
        );
    }

    private function normalise(array $p, ?array $owner): array
    {
        $rate = (float) data_get($p, 'currency.exchange_rate', 1) ?: 1;
        $max = data_get($p, 'budget.maximum');
        $min = data_get($p, 'budget.minimum');

        return [
            'id' => $p['id'],
            'title' => $p['title'],
            'url' => 'https://www.freelancer.com/projects/'.$p['seo_url'],
            'description' => $p['description'] ?? $p['preview_description'] ?? '',
            'type' => $p['type'] ?? 'fixed',
            'currency' => data_get($p, 'currency.code', 'USD'),
            'exchange_rate_to_usd' => $rate,
            'budget_min' => $min,
            'budget_max' => $max,
            'budget_max_usd' => $max !== null ? round($max * $rate, 2) : ($min !== null ? round($min * $rate, 2) : null),
            'bid_count' => data_get($p, 'bid_stats.bid_count', 0),
            'bid_avg' => data_get($p, 'bid_stats.bid_avg'),
            'bid_period_days' => $p['bidperiod'] ?? null,
            'language' => $p['language'] ?? null,
            'submitted_at' => $p['time_submitted'] ?? null,
            'skills' => array_map(fn ($j) => $j['name'], $p['jobs'] ?? []),
            'jobs' => array_map(fn ($j) => [
                'id' => $j['id'],
                'name' => $j['name'],
                'category_id' => data_get($j, 'category.id'),
            ], $p['jobs'] ?? []),
            'client' => $owner === null ? null : [
                'country' => data_get($owner, 'location.country.name'),
                'payment_verified' => data_get($owner, 'status.payment_verified'),
                'rating' => data_get($owner, 'employer_reputation.entire_history.overall'),
                'reviews' => data_get($owner, 'employer_reputation.entire_history.reviews'),
                'registered_at' => data_get($owner, 'registration_date'),
            ],
        ];
    }

    private function http(): PendingRequest
    {
        $request = Http::baseUrl(config('freelance.api_url'))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 2000)
            // IPv6 routing on this server is broken; force IPv4.
            ->withOptions(['force_ip_resolve' => 'v4']);

        if ($token = config('freelance.token')) {
            $request->withHeaders(['freelancer-oauth-v1' => $token]);
        }

        return $request;
    }
}
