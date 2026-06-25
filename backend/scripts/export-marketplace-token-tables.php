<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = array_values(array_filter(array_map('trim', explode(',', $argv[1] ?? 'shopee_config,shopee_tokens'))));
$output = $argv[2] ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stb-marketplace-tokens.sql';
$pdo = DB::connection()->getPdo();

$quoteIdent = static fn (string $value): string => '"'.str_replace('"', '""', $value).'"';
$quoteValue = static function (mixed $value) use ($pdo): string {
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }

    return $pdo->quote((string) $value);
};

$sql = [
    '-- Marketplace token table export generated '.date('c'),
    '-- Sensitive file: do not commit or paste publicly.',
    'BEGIN;',
];

foreach ($tables as $table) {
    if (! Schema::hasTable($table)) {
        fwrite(STDERR, "Skip missing table: {$table}\n");
        continue;
    }

    $sql[] = 'TRUNCATE TABLE '.$quoteIdent($table).' RESTART IDENTITY CASCADE;';

    foreach (DB::table($table)->orderBy('id')->get() as $row) {
        $data = (array) $row;
        $columns = array_keys($data);

        $sql[] = sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            $quoteIdent($table),
            implode(', ', array_map($quoteIdent, $columns)),
            implode(', ', array_map($quoteValue, array_values($data))),
        );
    }
}

$sql[] = 'COMMIT;';
$sql[] = '';

file_put_contents($output, implode(PHP_EOL, $sql));

echo "Exported ".implode(', ', $tables)." to {$output}".PHP_EOL;
