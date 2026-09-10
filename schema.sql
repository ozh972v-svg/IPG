-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица фото
CREATE TABLE IF NOT EXISTS photos (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key_type VARCHAR(10) NOT NULL,
    key_value VARCHAR(255) NOT NULL,
    photo_type VARCHAR(50) NOT NULL,
    comment TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    mime_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Индексы для быстрого поиска
CREATE INDEX IF NOT EXISTS idx_photos_key ON photos(key_type, key_value);
CREATE INDEX IF NOT EXISTS idx_photos_user ON photos(user_id);
CREATE INDEX IF NOT EXISTS idx_photos_created ON photos(created_at DESC);
