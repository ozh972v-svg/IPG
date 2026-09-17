-- Создаём таблицу keys, если её ещё нет (безопасно)
CREATE TABLE IF NOT EXISTS keys (
    key_type VARCHAR(10) NOT NULL,
    key_value VARCHAR(255) NOT NULL,
    PRIMARY KEY (key_type, key_value)
);

-- Добавляем новые колонки (безопасно: если есть — ничего не произойдёт)
ALTER TABLE keys ADD COLUMN IF NOT EXISTS gos_number   VARCHAR(20);
ALTER TABLE keys ADD COLUMN IF NOT EXISTS order_number VARCHAR(50);
ALTER TABLE keys ADD COLUMN IF NOT EXISTS user_id      INTEGER;
ALTER TABLE keys ADD COLUMN IF NOT EXISTS created_at   TIMESTAMP DEFAULT NOW();
ALTER TABLE keys ADD COLUMN IF NOT EXISTS updated_by   INTEGER;
ALTER TABLE keys ADD COLUMN IF NOT EXISTS updated_at   TIMESTAMP DEFAULT NOW();
