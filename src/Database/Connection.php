<?php
declare(strict_types=1);
namespace App\Database;
use App\Config\Config;
use PDO;
use RuntimeException;
final class Connection {
 private static ?string $generatedCaPath=null;
 public static function make(): PDO {
  $dbUrl=Config::get('DB_URL'); $urlOptions=[]; $user=null; $password=null;
  if(is_string($dbUrl)&&trim($dbUrl)!==''){
   $parts=parse_url(trim($dbUrl)); $scheme=strtolower((string)($parts['scheme']??''));
   if(!is_array($parts)||!in_array($scheme,['postgres','postgresql'],true)) throw new RuntimeException('DB_URL must use postgres:// or postgresql://.');
   $host=(string)($parts['host']??''); $port=(string)($parts['port']??'5432'); $name=ltrim((string)($parts['path']??''),'/');
   parse_str((string)($parts['query']??''),$urlOptions); $user=rawurldecode((string)($parts['user']??'')); $password=rawurldecode((string)($parts['pass']??''));
   if($host===''||$name===''||$user===''||!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('DB_URL must include a valid host, port, database and username.');
   $dsn=sprintf('pgsql:host=%s;port=%s;dbname=%s',$host,$port,$name);
  } else {
   $dsn=Config::get('DB_DSN');
   if(is_string($dsn)&&trim($dsn)!==''){ $dsn=trim($dsn); $user=(string)Config::get('DB_USER',''); $password=Config::get('DB_PASSWORD',''); }
   else {
    $host=Config::requireString('DB_HOST'); $name=Config::requireString('DB_NAME'); $user=Config::requireString('DB_USER'); $password=Config::get('DB_PASSWORD',''); $port=(string)Config::get('DB_PORT','5432');
    if(!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('DB_PORT must be a valid TCP port.');
    $dsn=sprintf('pgsql:host=%s;port=%s;dbname=%s',$host,$port,$name);
   }
  }
  $sslMode=strtolower((string)Config::get('DB_SSL_MODE',$urlOptions['sslmode']??$urlOptions['ssl-mode']??'require'));
  $modes=['disabled'=>'disable','required'=>'require','require'=>'require','verify-ca'=>'verify-full','verify-full'=>'verify-full'];
  if(!isset($modes[$sslMode])) throw new RuntimeException('DB_SSL_MODE must be disabled, required, or verify-ca.');
  $dsn.=';sslmode='.$modes[$sslMode];
  if($modes[$sslMode]==='verify-full'){
   $caFile=self::resolveCaFile(); if($caFile===null) throw new RuntimeException('DB_SSL_CA or DB_SSL_CA_PEM is required when DB_SSL_MODE=verify-ca.');
   $dsn.=';sslrootcert='.$caFile;
  }
  $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false];
  return new PostgresPdo((string)$dsn,(string)$user,(string)$password,$options);
 }
 private static function resolveCaFile(): ?string {
  $caPath=Config::get('DB_SSL_CA');
  if(is_string($caPath)&&trim($caPath)!==''){ if(!is_file($caPath)||!is_readable($caPath)) throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate file.'); return $caPath; }
  $caPem=Config::get('DB_SSL_CA_PEM'); if(!is_string($caPem)||trim($caPem)==='') return null;
  if(self::$generatedCaPath!==null&&is_file(self::$generatedCaPath)) return self::$generatedCaPath;
  $path=tempnam(sys_get_temp_dir(),'php-bot-db-ca-');
  if($path===false||file_put_contents($path,$caPem,LOCK_EX)===false) throw new RuntimeException('Unable to create temporary database CA certificate file.');
  chmod($path,0600); self::$generatedCaPath=$path; return $path;
 }
}
