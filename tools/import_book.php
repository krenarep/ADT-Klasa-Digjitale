<?php
// Përdorimi (CLI): php tools/import_book.php rruga/libri.zip ["Titulli"] [klasa]
if (PHP_SAPI !== 'cli') exit("Vetëm nga rreshti i komandës.\n");
require __DIR__ . '/../inc/bootstrap.php';
$zip = $argv[1] ?? '';
if (!is_file($zip)) exit("Skedari nuk u gjet: $zip\n");
$admin = q1("SELECT * FROM users WHERE role='admin' ORDER BY id LIMIT 1");
$id = import_book_zip($zip, ['title' => $argv[2] ?? '', 'grade' => isset($argv[3]) ? (int)$argv[3] : null], $admin);
$b = q1('SELECT * FROM books WHERE id=?', [$id]);
echo "U importua #$id: {$b['title']} ({$b['package_type']}, {$b['page_count']} faqe, {$b['activity_count']} ushtrime)\n";
