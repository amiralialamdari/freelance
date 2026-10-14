<?php

namespace App\Services;

use Anthropic\Client;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProposalWriter
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'relevant' => ['type' => 'boolean'],
            'relevance_note_fa' => ['type' => 'string'],
            'category' => ['type' => 'string', 'enum' => ['backend', 'frontend', 'fullstack', 'ai', 'iot', 'mobile', 'other']],
            'title_fa' => ['type' => 'string'],
            'summary_fa' => ['type' => 'string'],
            'key_requirements_fa' => ['type' => 'array', 'items' => ['type' => 'string']],
            'score' => ['type' => 'integer'],
            'score_reasons_fa' => ['type' => 'array', 'items' => ['type' => 'string']],
            'red_flags_fa' => ['type' => 'array', 'items' => ['type' => 'string']],
            'bid_amount' => ['type' => 'number'],
            'bid_is_hourly' => ['type' => 'boolean'],
            'delivery_days' => ['type' => 'integer'],
            'pricing_note_fa' => ['type' => 'string'],
            'proposal_en' => ['type' => 'string'],
            'proposal_fa' => ['type' => 'string'],
        ],
        'required' => [
            'relevant', 'relevance_note_fa', 'category', 'title_fa', 'summary_fa', 'key_requirements_fa',
            'score', 'score_reasons_fa', 'red_flags_fa', 'bid_amount', 'bid_is_hourly', 'delivery_days',
            'pricing_note_fa', 'proposal_en', 'proposal_fa',
        ],
        'additionalProperties' => false,
    ];

    /**
     * Analyse a normalised project and return the decoded analysis.
     *
     * @return array<string, mixed>
     */
    public function analyse(array $project): array
    {
        return config('freelance.provider') === 'anthropic'
            ? $this->assertComplete($this->analyseWithClaude($project))
            : $this->analyseWithZai($project);
    }

    private function assertComplete(array $data): array
    {
        foreach (self::SCHEMA['required'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new RuntimeException("Model response is missing \"{$key}\"");
            }
        }

        return $data;
    }

    /**
     * Z.ai GLM (OpenAI-compatible chat completions, JSON mode).
     */
    private function analyseWithZai(array $project): array
    {
        // The small GLM model occasionally returns malformed JSON; one retry usually fixes it.
        try {
            return $this->requestZai($project);
        } catch (RuntimeException) {
            return $this->requestZai($project);
        }
    }

    private function requestZai(array $project): array
    {
        $response = Http::baseUrl(config('freelance.zai_url'))
            ->withToken(config('freelance.zai_key'))
            ->timeout(300)
            ->retry(3, 5000, fn ($e) => ! $e instanceof RequestException || $e->response->status() === 429 || $e->response->serverError())
            // IPv6 routing on this server is broken; force IPv4.
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->post('/chat/completions', [
                'model' => config('freelance.zai_model'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()."\n\n".$this->jsonInstructions()],
                    ['role' => 'user', 'content' => $this->projectPrompt($project)],
                ],
                'response_format' => ['type' => 'json_object'],
                'thinking' => ['type' => 'enabled'],
                'max_tokens' => 16000,
            ])
            ->throw();

        $content = (string) $response->json('choices.0.message.content', '');
        // Tolerate fences or stray text around the object.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $data = $start !== false && $end > $start ? json_decode(substr($content, $start, $end - $start + 1), true) : null;

        if (! is_array($data)) {
            throw new RuntimeException('GLM returned invalid JSON (finish reason: '.$response->json('choices.0.finish_reason')
                .', '.json_last_error_msg().'): '.mb_substr($content, 0, 200));
        }

        return $this->assertComplete($data);
    }

    private function jsonInstructions(): string
    {
        return "## Output format\nReply with ONLY one JSON object, no other text, matching this JSON Schema exactly:\n"
            .json_encode(self::SCHEMA, JSON_UNESCAPED_SLASHES);
    }

    private function analyseWithClaude(array $project): array
    {
        $client = new Client(
            apiKey: config('freelance.anthropic_key'),
            requestOptions: [
                // IPv6 routing on this server is broken; force IPv4.
                'transporter' => new Guzzle(['force_ip_resolve' => 'v4', 'timeout' => 600]),
                'maxRetries' => 3,
            ],
        );

        $message = $client->beta->messages->create(
            model: config('freelance.model'),
            maxTokens: 16000,
            system: [
                ['type' => 'text', 'text' => $this->systemPrompt(), 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: [
                ['role' => 'user', 'content' => $this->projectPrompt($project)],
            ],
            outputConfig: [
                'effort' => config('freelance.effort'),
                'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
            ],
            // If a safety classifier declines, retry on a fallback model chosen by the server.
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Claude declined this project: '.($message->stopDetails?->category ?? 'unknown'));
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        throw new RuntimeException('Claude returned no JSON (stop reason: '.$message->stopReason.')');
    }

    private function projectPrompt(array $project): string
    {
        $data = $project;
        $data['submitted_at'] = $project['submitted_at'] ? gmdate('Y-m-d H:i', $project['submitted_at']).' UTC' : null;
        unset($data['jobs']);

        return "Here is a new Freelancer.com project. Analyse it and write the bid.\n\n<project>\n"
            .json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n</project>";
    }

    private function systemPrompt(): string
    {
        $p = config('freelance.profile');

        return <<<PROMPT
        You help {$p['name']}, a freelance software developer, triage new projects on Freelancer.com and write bids he can paste and send as-is. Everything in the JSON fields ending in `_fa` is read by him in Persian; `proposal_en` is sent to the client.

        About {$p['name']}:
        - Based in {$p['location']}, {$p['experience']} of professional experience.
        - Skills: {$p['focus']}.
        - Freelancer profile: {$p['stats']}. His portfolio is on his Freelancer profile.

        ## 1. Relevance
        Set `relevant` to true only for real software development work: backend, frontend, full-stack, mobile apps, AI/ML engineering (integrations, agents, chatbots, model work), automation/scripts, IoT and embedded. Set it to false for WordPress, SEO, marketing, data entry, content writing, pure graphic/UI design without code, AI art/video/voice, "train my AI by chatting", and anything that is not programming. When false, still fill every field briefly; the proposal fields can be empty strings.

        ## 2. Persian analysis
        - `title_fa`: the project title in natural Persian.
        - `summary_fa`: 2-4 short sentences: what the client wants and what the deliverable is.
        - `key_requirements_fa`: the concrete technical requirements, one per item.
        - Keep technical terms (Laravel, API, React...) in English inside the Persian text.

        ## 3. Score (`score`, integer 1-10)
        Weigh: fit with his skills (Laravel, Tailwind and IoT fit best), budget versus realistic scope, clarity of the brief, client quality (payment verified, rating, reviews, account age when given), competition (number of bids), and red flags (vague scope, unrealistic budget, unpaid test work, requests to move off-platform, possible scam). 8-10 means bid now, 5-7 worth a look, 1-4 skip. `score_reasons_fa` lists the main reasons, `red_flags_fa` the concerns (empty list if none).

        ## 4. Price and timeline
        - Bid in the project's currency. Aim close to the average of the existing bids (`bid_avg`), kept inside the client's budget range and sane for the actual scope. With no bids yet, use the middle of the budget, adjusted for scope.
        - For hourly projects `bid_is_hourly` is true and `bid_amount` is the hourly rate; for fixed projects it is the total.
        - `delivery_days`: faster than a typical freelancer would quote, but genuinely achievable for a senior developer. Never promise the impossible.
        - `pricing_note_fa`: one or two sentences explaining the price and timeline.

        ## 5. The proposal (`proposal_en`)
        This is what wins or loses the job. The client sees only the first two lines in the bid list, and reads dozens of bids, most of them generic.

        Structure:
        1. Open with "Hi," then one or two sentences that show you understood their specific problem, naming concrete details from their post. No self-introduction in the opening.
        2. Briefly explain what the job actually needs technically: the approach, the key pieces, any pitfall you'd handle. This is where expertise shows.
        3. A short plan of 3-4 numbered steps, one line each.
        4. Timeline in days, matching `delivery_days`, plus a reasonable support offer sized to the project (for example a couple of weeks of free bug fixes after delivery for small jobs, around 30 days for larger ones).
        5. Mention his 10+ years once, naturally, and that similar work is in the portfolio on his profile. If relevant, mention he's in Canada, which suits North American clients' hours.
        6. Ask one or two sharp questions only when they'd genuinely change the approach or estimate; otherwise ask none.
        7. Sign off with "Best,\n{$p['name']}".

        Length: medium, about 120-200 words and never above 1,400 characters (Freelancer caps bid text at 1,500). Short paragraphs, easy to skim.

        It must read as written by an experienced human developer, not by AI:
        - Plain, confident, conversational English with contractions. Vary sentence length.
        - Never use: "I hope this message finds you well", "I came across your project", "I am excited", "Dear Hiring Manager", "leverage", "seamless", "robust", "cutting-edge", "delve", "tailored", "elevate", "top-notch", "I'd be thrilled".
        - No em dashes, no headings, no bold, no emoji, no bullet symbols other than the numbered plan.
        - Don't restate the job post back to them, don't flatter, don't oversell.
        - Never invent specific past clients, project names, or numbers he hasn't given you.
        - Follow Freelancer.com rules: no email, phone, Skype, links, or invitations to talk off-platform.
        - Don't state the price in the text unless the post explicitly asks for a quote breakdown.

        `proposal_fa` is a faithful, natural Persian translation of `proposal_en` so he knows exactly what he's sending.
        PROMPT;
    }
}
