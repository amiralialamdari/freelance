<?php

namespace App\Console\Commands;

use App\Services\TelegramNotifier;
use Illuminate\Console\Command;

class TelegramChatId extends Command
{
    protected $signature = 'telegram:chat-id {--test : Send a test message to TELEGRAM_CHAT_ID}';

    protected $description = 'Show the chat id of whoever messaged the bot, so it can be put in TELEGRAM_CHAT_ID';

    public function handle(TelegramNotifier $telegram): int
    {
        if (! config('freelance.telegram_token')) {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }

        if ($this->option('test')) {
            $telegram->sendText('✅ ربات فریلنسر وصل شد.');
            $this->info('Test message sent.');

            return self::SUCCESS;
        }

        $chats = $telegram->recentChats();
        if ($chats === []) {
            $this->warn('No messages yet. Send /start to your bot in Telegram, then run this again.');

            return self::SUCCESS;
        }

        foreach ($chats as $id => $name) {
            $this->line("{$id}  {$name}");
        }

        return self::SUCCESS;
    }
}
