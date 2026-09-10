<?php
declare(strict_types=1);

if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/import_schoology_roster.php /path/to/gradebook-export.csv\n");
    exit(1);
}

require __DIR__ . '/../app/bootstrap.php';

$db = db();
foreach ([
    'ALTER TABLE members ADD COLUMN schoology_user_id CHAR(36) NULL AFTER graduation_year',
    'ALTER TABLE members ADD COLUMN schoology_username VARCHAR(50) NULL AFTER schoology_user_id',
    'ALTER TABLE members ADD INDEX idx_schoology_user_id (schoology_user_id)',
] as $statement) {
    try {
        $db->exec($statement);
    } catch (PDOException $error) {
        if (strpos($error->getMessage(), 'Duplicate column name') === false && strpos($error->getMessage(), 'Duplicate key name') === false) {
            throw $error;
        }
    }
}

$handle = fopen($argv[1], 'r');
$headers = fgetcsv($handle, 0, ',', '"', '\\');
$columns = array_flip($headers ?: []);
foreach (['First Name', 'Last Name', 'Unique User ID', 'Username'] as $required) {
    if (!array_key_exists($required, $columns)) {
        throw new RuntimeException("The CSV is missing the {$required} column.");
    }
}

$update = $db->prepare('UPDATE members SET schoology_user_id = ?, schoology_username = ? WHERE first_name = ? AND last_name = ?');
$updated = 0;
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $first = trim((string)($row[$columns['First Name']] ?? ''));
    $last = trim((string)($row[$columns['Last Name']] ?? ''));
    if ($first === '' || $last === '') {
        continue;
    }
    $update->execute([
        trim((string)($row[$columns['Unique User ID']] ?? '')) ?: null,
        trim((string)($row[$columns['Username']] ?? '')) ?: null,
        $first,
        $last,
    ]);
    $updated += $update->rowCount();
}
fclose($handle);
echo "Updated {$updated} member records.\n";
