-- Норма времени (в часах) — заполнится после подключения метода 1С
ALTER TABLE work_operations ADD COLUMN IF NOT EXISTS norm_time NUMERIC(10,3);

-- Индекс для быстрого поиска работ по коду операции
CREATE INDEX IF NOT EXISTS idx_work_op_code ON work_operations(operation_code);

-- Чистим дубликаты: для работ с одинаковым operation_code оставляем одну (наименьший code)
DELETE FROM work_operations w
WHERE w.it_is_group = FALSE
  AND w.operation_code IS NOT NULL
  AND w.operation_code <> ''
  AND EXISTS (
      SELECT 1 FROM work_operations w2
      WHERE w2.it_is_group = FALSE
        AND w2.operation_code = w.operation_code
        AND w2.code < w.code
  );

-- Чистим удалённые
DELETE FROM work_operations WHERE deleted = TRUE;
