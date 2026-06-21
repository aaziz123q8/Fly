<?php
// Copy this file to config/database.php and fill in real values
// config/database.php is NOT committed to version control

return [
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'YOUR_DATABASE_NAME',
    'username' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'charset'  => 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
