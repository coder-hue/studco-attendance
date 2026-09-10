<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$names = <<<'NAMES'
Ella Adamson
Lincoln Aguilar
Akinwande Akingbulugbe
Adil Ansari
William Armstrong
Amelia Arnold
Jeremy Asiedu
Hanahiz Barajas Jimenez
John Borzeka
Beau Brown
Nathan Clysdale
Maria De La Torre Del Toro
Ava Eschweiler
Nina Faulkner
Clara Fedunok
Shia Funk
Danny Garcia
Lauren Goss
Soleil Gutierrez
Nash Johnson
Amelia Jones
Mannat Kamal
Aili Klett
Cameron Kluchar
Chearyen Ko
Charles Kodl
Izzabella Krech
Millie Minnichsoffer
Molly Monson
Eva Muchungu
Grace Muluneh
Phoebie Noha
Amelia Olives
Clara Olives
Maria Olives
Henry Olson
Macy Olson
Rida Othman
Faith Pha
Lillian Ree
Norah Ree
Daelyn Ricks
Kahler Rudin
Isabelle Schumacher
Iris Sorenson-Wagner
Graham Toelke
Emma Tousignant
Dike Umeh
Rhyan Uribe Gilmour
Breckin Whynott
Edward Widen
Margaret Wolsky
Jack Opgrande
NAMES;

$find = db()->prepare('SELECT id FROM members WHERE LOWER(first_name) = ? AND LOWER(last_name) = ? LIMIT 1');
$insert = db()->prepare('INSERT INTO members (first_name, last_name, active) VALUES (?, ?, 1)');
$added = 0;
$existing = 0;

foreach (preg_split('/\R/', trim($names)) as $fullName) {
    [$firstName, $lastName] = explode(' ', $fullName, 2);
    $find->execute([mb_strtolower($firstName), mb_strtolower($lastName)]);
    if ($find->fetchColumn()) {
        $existing++;
        continue;
    }
    $insert->execute([$firstName, $lastName]);
    $added++;
}

fwrite(STDOUT, "Roster seed complete: {$added} added, {$existing} already existed.\n");
