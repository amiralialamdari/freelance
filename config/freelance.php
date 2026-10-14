<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Freelancer.com API
    |--------------------------------------------------------------------------
    |
    | The public API works without a token, but the client's country is only
    | returned with a personal access token, so the country filter is skipped
    | when FREELANCER_TOKEN is empty.
    |
    */

    'api_url' => env('FREELANCER_API_URL', 'https://www.freelancer.com/api'),
    'token' => env('FREELANCER_TOKEN'),
    'fetch_limit' => (int) env('FREELANCER_FETCH_LIMIT', 100),

    // How many new projects to analyse per scan, and how old a pending one may get.
    'max_per_scan' => (int) env('FREELANCE_MAX_PER_SCAN', 15),
    'max_age_minutes' => (int) env('FREELANCE_MAX_AGE_MINUTES', 360),

    // Skip projects whose maximum budget (in USD) is below this. 0 = keep all.
    'min_budget_usd' => (float) env('FREELANCE_MIN_BUDGET_USD', 0),

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    // Freelancer category ids: 1 = Websites, IT & Software, 5 = Engineering & Science (IoT),
    // 104 = Artificial Intelligence.
    'categories' => [1, 5, 104],

    // A project is dropped if ANY of its skills matches one of these.
    'excluded_job_keywords' => ['wordpress'],

    // A project needs at least one skill in the categories above matching one of these...
    'programming_job_keywords' => [
        'php', 'laravel', 'symfony', 'javascript', 'typescript', 'node.js', 'nodejs', 'express js', 'react', 'react.js',
        'vue', 'vue.js', 'angular', 'angularjs', 'next.js', 'nuxt', 'svelte', 'html', 'html5', 'css', 'tailwind',
        'tailwind css', 'bootstrap', 'jquery', 'python', 'django', 'flask', 'fastapi', 'java', 'kotlin', 'swift',
        'c#', '.net', 'asp.net', 'c++', 'c programming', 'go', 'golang', 'rust', 'ruby', 'ruby on rails', 'sql',
        'mysql', 'postgresql', 'mongodb', 'redis', 'database', 'api', 'rest api', 'graphql', 'backend', 'frontend',
        'full stack', 'web development', 'web application', 'software development', 'software architecture',
        'mobile app development', 'android', 'iphone', 'ios', 'flutter', 'react native', 'docker', 'kubernetes',
        'aws', 'devops', 'linux', 'web scraping', 'automation', 'machine learning', 'deep learning',
        'artificial intelligence', 'ai', 'llm', 'openai', 'chatgpt', 'chatbot', 'nlp', 'computer vision',
        'tensorflow', 'pytorch', 'arduino', 'iot', 'internet of things', 'microcontroller', 'embedded',
        'raspberry pi', 'esp32', 'firmware', 'shopify development', 'saas',
    ],

    // ...that is not one of these non-programming kinds of work.
    'non_programming_job_keywords' => [
        'art', 'image', 'video', 'music', 'voice', 'content', 'writing', 'influencer',
        'ugc', 'avatar', 'animation', 'design', 'graphic', 'rendering', 'slop',
        'text-to-speech', 'translation', 'upskilling', 'policy', 'ethics', 'director',
    ],

    'excluded_countries' => ['India', 'Bangladesh', 'Pakistan'],

    /*
    |--------------------------------------------------------------------------
    | Claude
    |--------------------------------------------------------------------------
    */

    'anthropic_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('FREELANCE_MODEL', 'claude-opus-5-5'),
    'effort' => env('FREELANCE_EFFORT', 'medium'),

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
    'telegram_chat_id' => env('TELEGRAM_CHAT_ID'),
    // Projects scoring below this are not sent. 0 = send every relevant project.
    'min_score' => (int) env('FREELANCE_MIN_SCORE', 0),

    /*
    |--------------------------------------------------------------------------
    | Freelancer profile used in proposals
    |--------------------------------------------------------------------------
    */

    'profile' => [
        'name' => env('FREELANCE_NAME', 'Charles'),
        'location' => 'Canada',
        'experience' => '10+ years',
        'focus' => 'all-round software development, strongest in Laravel and Tailwind CSS; also IoT / embedded (Arduino, ESP32)',
        'stats' => '5.0 rating, 100% completion, 100% on time, 100% on budget (new account, 2 reviews)',
    ],

];
