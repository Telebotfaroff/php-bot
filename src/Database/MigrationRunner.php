<?php
declare(strict_types=1);
namespace App\Database;
use PDO;
use RuntimeException;
final class MigrationRunner {
 public function __construct(private readonly PDO $pdo,private readonly string $migrationDirectory){}
 /** @return list<string> */
 public function migrate(): array {
  if(!is_dir($this->migrationDirectory)) throw new RuntimeException('Migration directory does not exist.');
  $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)');
  $files=glob(rtrim($this->migrationDirectory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php');
  if($files===false) throw new RuntimeException('Unable to list database migrations.');
  sort($files,SORT_STRING); $applied=[];
  $check=$this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
  $record=$this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
  foreach($files as $file){
   $name=basename($file); $statements=require $file;
   if(!is_array($statements)) throw new RuntimeException('Migration must return an array of SQL statements: '.$name);
   $check->execute(['migration'=>$name]); if($check->fetchColumn()!==false) continue;
   foreach($statements as $statement){
    if(!is_string($statement)||trim($statement)==='') throw new RuntimeException('Invalid SQL statement in migration: '.$name);
    foreach(PostgresDialect::migrationStatements($statement) as $sql) if(trim($sql)!=='') $this->pdo->exec($sql);
   }
   $record->execute(['migration'=>$name]); $applied[]=$name;
  }
  $this->ensureUpdatedAtTriggers(); return $applied;
 }
 private function ensureUpdatedAtTriggers(): void {
  $this->pdo->exec("CREATE OR REPLACE FUNCTION php_bot_touch_updated_at() RETURNS trigger AS \$\$ BEGIN NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW; END; \$\$ LANGUAGE plpgsql");
  $query=$this->pdo->query("SELECT table_name FROM information_schema.columns WHERE table_schema=current_schema() AND column_name='updated_at'");
  foreach($query->fetchAll(PDO::FETCH_COLUMN) as $table){
   $table=(string)$table; if(!preg_match('/^[A-Za-z0-9_]+$/',$table)) continue;
   $trigger='trg_'.$table.'_updated_at';
   $this->pdo->exec('DROP TRIGGER IF EXISTS "'.$trigger.'" ON "'.$table.'"');
   $this->pdo->exec('CREATE TRIGGER "'.$trigger.'" BEFORE UPDATE ON "'.$table.'" FOR EACH ROW EXECUTE FUNCTION php_bot_touch_updated_at()');
  }
 }
}
