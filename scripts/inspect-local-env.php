<?php

require __DIR__.'/../backend/vendor/autoload.php';

foreach (['.env', '.env.testing'] as $name) {
    $values = Dotenv\Dotenv::parse(file_get_contents(__DIR__.'/../backend/'.$name));
    echo $name.': DB_DATABASE='.($values['DB_DATABASE'] ?? '(belum diisi)').'; DB_USERNAME '.(empty($values['DB_USERNAME']) ? 'belum diisi' : 'sudah diisi').PHP_EOL;
}
