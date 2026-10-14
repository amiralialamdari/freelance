<?php

namespace App\Services;

use App\Models\FreelanceProject;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramNotifier
{
    /**
     * Send a project as three messages: the overview, the English proposal
     * (in a code block, so one tap copies it) and the Persian translation.
     */
    public function sendProject(FreelanceProject $project): void
    {
        $p = $project->payload;
        $a = $project->analysis;

        $this->send($this->overview($p, $a), $this->linkButton($p['url']));
        $this->send("📝 <b>پروپوزال انگلیسی</b> (برای کپی روی متن بزن):\n\n<pre>".$this->e($a['proposal_en']).'</pre>');
        $this->send("🇮🇷 <b>ترجمه‌ی پروپوزال</b>\n\n".$this->e($a['proposal_fa']));
    }

    public function sendText(string $text): void
    {
        $this->send($this->e($text));
    }

    /**
     * Chats that have messaged the bot recently, for finding TELEGRAM_CHAT_ID.
     *
     * @return array<int|string, string>
     */
    public function recentChats(): array
    {
        $updates = $this->http()->get('getUpdates')->throw()->json('result', []);
        $chats = [];
        foreach ($updates as $update) {
            $chat = data_get($update, 'message.chat');
            if ($chat) {
                $chats[$chat['id']] = trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '').' @'.($chat['username'] ?? ''));
            }
        }

        return $chats;
    }

    private function overview(array $p, array $a): string
    {
        $stars = str_repeat('★', max(0, min(10, (int) $a['score']))).str_repeat('☆', 10 - max(0, min(10, (int) $a['score'])));
        $currency = $p['currency'];
        $budget = $this->money($p['budget_min'], $currency).' – '.$this->money($p['budget_max'], $currency);
        $hourly = $p['type'] === 'hourly' ? ' /ساعت' : '';
        $bidAvg = $p['bid_avg'] !== null ? $this->money($p['bid_avg'], $currency) : '—';
        $client = $p['client'];

        $lines = [
            '🆕 <b>'.$this->e($a['title_fa']).'</b>',
            '<i>'.$this->e($p['title']).'</i>',
            '',
            "⭐ امتیاز: <b>{$a['score']}/10</b>  {$stars}",
            '🏷 دسته: '.$this->e($a['category']).' | نوع: '.($p['type'] === 'hourly' ? 'ساعتی' : 'قیمت ثابت'),
            "💰 بودجه: {$budget}{$hourly}",
            "👥 پیشنهادها: {$p['bid_count']} | میانگین: {$bidAvg}",
        ];

        if ($client) {
            $verified = $client['payment_verified'] ? '✅ پرداخت تأییدشده' : '⚠️ پرداخت تأییدنشده';
            $rating = $client['reviews'] ? " | امتیاز کارفرما: {$client['rating']} ({$client['reviews']} نظر)" : '';
            $lines[] = '🌍 کارفرما: '.$this->e($client['country'] ?? '؟')." | {$verified}{$rating}";
        }

        $lines[] = '🛠 '.$this->e(implode(', ', array_slice($p['skills'], 0, 8)));
        $lines[] = '';
        $lines[] = '📄 <b>خلاصه</b>';
        $lines[] = $this->e($a['summary_fa']);

        if ($a['key_requirements_fa']) {
            $lines[] = '';
            $lines[] = '📌 <b>نیازمندی‌ها</b>';
            foreach ($a['key_requirements_fa'] as $item) {
                $lines[] = '• '.$this->e($item);
            }
        }

        $lines[] = '';
        $lines[] = '📊 <b>دلایل امتیاز</b>';
        foreach ($a['score_reasons_fa'] as $item) {
            $lines[] = '• '.$this->e($item);
        }

        if ($a['red_flags_fa']) {
            $lines[] = '';
            $lines[] = '🚩 <b>هشدارها</b>';
            foreach ($a['red_flags_fa'] as $item) {
                $lines[] = '• '.$this->e($item);
            }
        }

        $bid = $this->money($a['bid_amount'], $currency).($a['bid_is_hourly'] ? ' در ساعت' : '');
        $lines[] = '';
        $lines[] = "💵 <b>پیشنهاد قیمت: {$bid}</b> | ⏱ <b>{$a['delivery_days']} روز</b>";
        $lines[] = $this->e($a['pricing_note_fa']);

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    private function money(float|int|null $amount, string $currency): string
    {
        return $amount === null ? '—' : rtrim(rtrim(number_format((float) $amount, 2), '0'), '.').' '.$currency;
    }

    private function linkButton(string $url): array
    {
        return ['inline_keyboard' => [[['text' => '🔗 باز کردن پروژه', 'url' => $url]]]];
    }

    private function send(string $html, ?array $keyboard = null): void
    {
        $this->http()->post('sendMessage', array_filter([
            'chat_id' => config('freelance.telegram_chat_id'),
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard,
        ]))->throw();
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl('https://api.telegram.org/bot'.config('freelance.telegram_token').'/')
            ->timeout(30)
            ->retry(3, 2000, throw: false)
            // IPv6 routing on this server is broken; force IPv4.
            ->withOptions(['force_ip_resolve' => 'v4']);
    }
}
