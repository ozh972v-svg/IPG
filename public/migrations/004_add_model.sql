-- Модели автотехники, по которым загружены справочники работ
CREATE TABLE IF NOT EXISTS work_models (
    code        VARCHAR(50) PRIMARY KEY,     -- '54901'
    name        VARCHAR(255) NOT NULL,       -- 'КАМАЗ 54901'
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Колонка "модель" в справочнике работ
ALTER TABLE work_operations
    ADD COLUMN IF NOT EXISTS model VARCHAR(50);

-- Индекс для быстрой фильтрации
CREATE INDEX IF NOT EXISTS idx_work_model ON work_operations(model);

-- Всё, что уже загружено (старые данные без модели), пометим как 54901
UPDATE work_operations SET model = '54901' WHERE model IS NULL;

-- Регистрируем модель, если её нет
INSERT INTO work_models (code, name)
VALUES ('54901', 'КАМАЗ 54901')
ON CONFLICT (code) DO NOTHING;
