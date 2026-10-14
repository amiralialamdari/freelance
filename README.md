# Freelancer Bot

Watches Freelancer.com for new programming projects (backend, frontend, AI, IoT), has Claude
translate, summarise, score and price each one, writes a ready-to-send proposal, and sends it
all to Telegram.

## How it works

1. `freelancer:scan` runs every 2 minutes (Laravel scheduler) and pulls the 100 newest active projects.
2. `ProjectFilter` keeps projects with a real programming skill and drops WordPress and
   clients from India, Bangladesh and Pakistan (country needs `FREELANCER_TOKEN`).
3. `ProposalWriter` sends each new project to Claude, which returns structured JSON:
   relevance, Persian summary, score 1-10 with reasons, suggested bid and timeline,
   English proposal and its Persian translation.
4. `TelegramNotifier` sends three messages per project: overview, the English proposal in a
   tap-to-copy block, and the Persian translation.

Proposal rules live in `app/Services/ProposalWriter.php`; filters and profile in `config/freelance.php`.

## Setup

Requires PHP 8.3+ with curl, mbstring, openssl, pdo_sqlite.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Fill in `.env`:

| Key | Where to get it |
|---|---|
| `ANTHROPIC_API_KEY` | platform.claude.com → API keys |
| `TELEGRAM_BOT_TOKEN` | Telegram → @BotFather → `/newbot` |
| `TELEGRAM_CHAT_ID` | Send `/start` to your bot, then run `php artisan telegram:chat-id` |
| `FREELANCER_TOKEN` (optional) | accounts.freelancer.com/settings/develop → personal access token |

Check it:

```bash
php artisan telegram:chat-id --test   # sends a test message
php artisan freelancer:scan --dry     # analyses without sending to Telegram
```

## Run

```bash
php artisan schedule:work
```

On Windows, `start-bot.bat` does the same. To start it automatically at boot, register it with
Task Scheduler. On Linux, add the usual cron entry: `* * * * * php /path/artisan schedule:run`.

The first scan only analyses the 3 newest matching projects; older ones are marked as seen.

## Settings

| Key | Default | Meaning |
|---|---|---|
| `FREELANCE_MODEL` | `claude-opus-5-5` | Claude model |
| `FREELANCE_EFFORT` | `medium` | `low` / `medium` / `high`: quality vs cost |
| `FREELANCE_MIN_BUDGET_USD` | `0` | Skip projects whose max budget is below this |
| `FREELANCE_MIN_SCORE` | `0` | Only send projects scoring at least this |
| `FREELANCE_MAX_PER_SCAN` | `15` | Projects analysed per 2-minute run |
