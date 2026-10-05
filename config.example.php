<?php
return [
  'local_test_mode' => false, // never true on a public host
  // SQLite alternative — keep the file OUTSIDE the web root (nginx serves static files directly):
  // 'db' => ['driver'=>'sqlite','path'=>'/home/user/famfin-data/budget.sqlite'],
  'bot_token' => 'PASTE_NEW_TOKEN_HERE',
  'webhook_secret' => 'SET_A_RANDOM_SECRET_HERE',
  'app_url' => 'https://example.com',
  'bot_username' => '', // optional: e.g. 'my_budget_bot', avoids a getMe call per page load
  'report_chat_id' => '', // weekly report target, e.g. '-1001234567890' (bot must be a channel admin)
  'widget_chat_id' => '', // pinned balance card; defaults to report_chat_id (bot needs edit + pin rights)
  'backup_chat_id' => '', // Telegram id that receives the daily database backup and may use /backup
  // Receipt photos: node + jsqr/jimp installed in tools_dir (npm install jsqr jimp)
  'node_path' => '/path/to/node',
  'tools_dir' => '/path/to/famfin-tools',
  // Optional: answers to free-form questions by Claude (composer require anthropic-ai/sdk in that folder)
  'anthropic_api_key' => '',
  'anthropic_autoload' => '/path/to/famfin-lib/vendor/autoload.php',
  'db' => ['driver'=>'mysql','host'=>'localhost','name'=>'database','user'=>'username','password'=>'password','charset'=>'utf8mb4'],
  'allowed_users' => [854102139, 995540516],
  'user_labels' => [854102139 => 'Миржан', 995540516 => 'Томирис'],
  'currency' => 'KZT',
  'timezone' => 'Asia/Qyzylorda',
  'category_groups' => [
    'fixed' => ['Продукты','Дом','Транспорт','Здоровье','Дети','Подписки','Кредиты'],
    'variable' => ['Кафе и рестораны','Покупки','Путешествия','Развлечения','Другое'],
  ],
];
