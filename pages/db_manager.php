<?php
// /var/www/fapa.io/fapacrm/pages/db_manager.php
declare(strict_types=1);

csrf_check();
$db = db_connect();

// ✅ bootstrap: migrations table always exists
$db->query("
  CREATE TABLE IF NOT EXISTS crm_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
if ($db->error) {
  throw new RuntimeException("Failed to ensure crm_migrations: " . $db->error);
}

function split_sql_statements(string $sql): array {
  // remove line comments
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);

  // split by ; followed by newline
  $parts = preg_split('/;\s*[\r\n]+/', $sql);
  if (!is_array($parts)) $parts = [];

  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p !== '') $out[] = $p;
  }
  return $out;
}

function is_ignorable_sql_error(mysqli $db): bool {
  $errno = (int)$db->errno;
  $err   = (string)$db->error;

  // MySQL codes that are safe to ignore for idempotent migrations
  // 1060 Duplicate column, 1061 Duplicate key, 1062 Duplicate entry, 1050 Table exists, 1091 Can't drop, 1146 Table doesn't exist
  if ($errno === 1060) return true;
  if ($errno === 1061) return true;
  if ($errno === 1062) return true;
  if ($errno === 1050) return true;
  if ($errno === 1091) return true;
  if ($errno === 1146) return true;

  // Message-based fallback
  $patterns = [
    'Duplicate column name',
    'Duplicate key name',
    'Duplicate entry',
    'already exists',
    "doesn't exist",
    'Table already exists',
  ];
  foreach ($patterns as $p) {
    if (stripos($err, $p) !== false) return true;
  }

  return false;
}

function apply_migrations(mysqli $db, string $dir): array {
  if (!is_dir($dir)) {
    return ['ok'=>false, 'msg'=>'migrations dir not found: '.$dir];
  }

  $files = glob(rtrim($dir,'/')."/*.sql");
  if (!is_array($files)) $files = [];
  sort($files);

  $applied = 0;
  $skipped = 0;
  $totalFiles = count($files);

  foreach ($files as $file) {
    $name = basename((string)$file);

    $exists = db_scalar($db, "SELECT 1 FROM crm_migrations WHERE name=? LIMIT 1", [$name]);
    if ($exists) { $skipped++; continue; }

    $sql = (string)file_get_contents((string)$file);
    $stmts = split_sql_statements($sql);

    $db->begin_transaction();
    try {
      $ignored = 0;

      foreach ($stmts as $stmt) {
        $db->query($stmt);

        if ($db->error) {
          if (is_ignorable_sql_error($db)) {
            // ✅ ignore duplicates / exists errors and continue
            $ignored++;
            continue;
          }

          throw new RuntimeException(
            "SQL error in {$name}: ".$db->error."\n\nSTATEMENT:\n".$stmt
          );
        }
      }

      db_exec($db, "INSERT INTO crm_migrations(name) VALUES(?)", [$name]);
      $db->commit();
      $applied++;

    } catch (Throwable $e) {
      $db->rollback();
      return ['ok'=>false, 'msg'=>$e->getMessage(), 'file'=>$name];
    }
  }

  return ['ok'=>true, 'applied'=>$applied, 'skipped'=>$skipped, 'files'=>$totalFiles];
}

function schema_json(mysqli $db, string $dbName): array {
  $tables = db_all($db, "SELECT TABLE_NAME, TABLE_COMMENT
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=?
    ORDER BY TABLE_NAME", [$dbName]);

  $out = ['db'=>$dbName, 'generated_at'=>date('c'), 'tables'=>[]];

  foreach ($tables as $t) {
    $tn = (string)$t['TABLE_NAME'];

    $cols = db_all($db, "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
      ORDER BY ORDINAL_POSITION", [$dbName, $tn]);

    $idx = db_all($db, "SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
      ORDER BY INDEX_NAME, SEQ_IN_INDEX", [$dbName, $tn]);

    $fks = db_all($db, "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
      FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION", [$dbName, $tn]);

    $out['tables'][$tn] = [
      'comment' => (string)($t['TABLE_COMMENT'] ?? ''),
      'columns' => $cols,
      'indexes' => $idx,
      'foreign_keys' => $fks,
    ];
  }

  return $out;
}

// ===== actions =====
$do_migrate = req_bool('do_migrate', false);
$do_schema  = req_bool('do_schema', false);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do_migrate) {
  $res = apply_migrations($db, __DIR__ . '/../migrations');

  if (!empty($res['ok'])) {
    flash_set('success', "Миграции: применено ".$res['applied'].", пропущено ".$res['skipped'].", файлов ".$res['files']);
  } else {
    $file = isset($res['file']) ? (string)$res['file'] : 'unknown';
    $msg  = isset($res['msg']) ? (string)$res['msg'] : 'unknown error';
    flash_set('danger', "Ошибка миграции ($file): ".$msg);
  }

  header('Location: menu.php?page=db_manager');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $do_schema) {
  $schema = schema_json($db, (string)BM_DB_NAME);
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="schema_'.BM_DB_NAME.'_'.date('Ymd_His').'.json"');
  echo json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  exit;
}

// ===== page data =====
$migs = db_all($db, "SELECT * FROM crm_migrations ORDER BY applied_at DESC");

$files = glob(__DIR__ . '/../migrations/*.sql');
if (!is_array($files)) $files = [];
sort($files);
$tmp = [];
foreach ($files as $f) $tmp[] = basename((string)$f);
$files = $tmp;

$appliedMap = [];
foreach ($migs as $m) $appliedMap[(string)$m['name']] = (string)$m['applied_at'];

?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">🗄️ Миграции + схема JSON</h3>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="post" class="d-flex gap-2 flex-wrap">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <button class="btn btn-primary" name="do_migrate" value="1">Применить миграции</button>
      <button class="btn btn-outline-secondary" name="do_schema" value="1">Скачать JSON схемы</button>
      <div class="text-muted small align-self-center">DB: <code><?=h((string)BM_DB_NAME)?></code></div>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><b>Файлы миграций</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Файл</th><th>Статус</th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr>
                <td><code><?=h($f)?></code></td>
                <td>
                  <?php if (isset($appliedMap[$f])): ?>
                    <span class="badge text-bg-success">applied</span>
                    <span class="text-muted small ms-2"><?=h($appliedMap[$f])?></span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary">pending</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$files): ?><tr><td colspan="2" class="text-muted">Нет *.sql в папке migrations</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><b>Применённые миграции</b></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>ID</th><th>Файл</th><th>Дата</th></tr></thead>
          <tbody>
            <?php foreach ($migs as $m): ?>
              <tr>
                <td><?=h($m['id'])?></td>
                <td><code><?=h($m['name'])?></code></td>
                <td><?=h($m['applied_at'])?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$migs): ?><tr><td colspan="3" class="text-muted">Пока пусто</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
