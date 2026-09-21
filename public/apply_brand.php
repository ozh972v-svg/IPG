<?php
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = get_db();

    $db->exec("ALTER TABLE work_operations ADD COLUMN IF NOT EXISTS brand VARCHAR(16) NOT NULL DEFAULT 'KAMAZ'");
    echo "1. Поле brand добавлено (или уже было).\n";

    $db->exec("CREATE INDEX IF NOT EXISTS idx_wo_brand_comp ON work_operations (brand, complectation)");
    echo "2. Индекс idx_wo_brand_comp создан (или уже был).\n";

    // Проверим, что всё ок
    $cnt = $db->query("SELECT COUNT(*) FROM work_operations")->fetchColumn();
    echo "3. Записей в work_operations: $cnt\n";

    echo "\nГОТОВО. Теперь удали этот файл (apply_brand.php).\n";
} catch (Throwable $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}
