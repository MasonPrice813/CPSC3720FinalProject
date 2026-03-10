<?php

class Database
{
   private PDO $pdo;

   public function __construct()
   {
       $databaseUrl = getenv('DATABASE_URL');

       if (!$databaseUrl) {
           throw new Exception("DATABASE_URL environment variable is not set.");
       }

       $db = parse_url($databaseUrl);

       if ($db === false) {
           throw new Exception("Invalid DATABASE_URL format.");
       }

       $host = $db['host'];
       $port = $db['port'] ?? 5432;
       $dbname = ltrim($db['path'], '/');
       $user = $db['user'];
       $pass = $db['pass'];

       $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

       $this->pdo = new PDO(
           $dsn,
           $user,
           $pass,
           [
               PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
           ]
       );
   }

   public function pdo(): PDO
   {
       return $this->pdo;
   }
}

