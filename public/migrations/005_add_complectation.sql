-- Комплектация ТС, к которой относится работа
ALTER TABLE work_operations
    ADD COLUMN IF NOT EXISTS complectation VARCHAR(100);

-- Индекс для быстрой фильтрации по комплектации
CREATE INDEX IF NOT EXISTS idx_work_complectation
    ON work_operations(complectation);

-- Всё, что уже загружено (без комплектации), пометим как «без комплектации»
UPDATE work_operations
SET complectation = '(без комплектации)'
WHERE complectation IS NULL;
