<?php
/**
 * Пример конфигурации FapaCRM. Скопируйте в config.php и заполните свои значения.
 * НЕ коммитьте реальные config.php / config.user.php — в них пароли и токены.
 */
return [
  // Basic-auth панели
  'admin_user' => 'CHANGE_ME',
  'admin_pass_hash' => 'CHANGE_ME',

  // MySQL: привилегированный доступ к БД fapacrm и БД клонов db_<domain>
  'db_host' => 'localhost',
  'db_name' => 'fapacrm',
  'db_user' => 'CHANGE_ME',
  'db_pass' => 'CHANGE_ME',

  // Внешние API (токены хранить только на сервере, не в репозитории)
  'kadam_bearer' => 'CHANGE_ME',
  'kadam_app_id' => 'CHANGE_ME',
  'tacoloco_token' => 'CHANGE_ME',
  'vibemetrics_agent_key' => 'CHANGE_ME',

  // Путь к пользовательскому конфигу (например /var/www/pornfluxx.ru/fapacrm/config.user.php)
  'user_config_path' => 'CHANGE_ME',
];
