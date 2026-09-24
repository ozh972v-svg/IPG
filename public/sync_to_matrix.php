CREATE TABLE IF NOT EXISTS to_matrices (
    id             SERIAL PRIMARY KEY,
    complectation  VARCHAR(100) UNIQUE NOT NULL,
    title          VARCHAR(500),
    brand          VARCHAR(50) DEFAULT 'KAMAZ',
    source_file    VARCHAR(300),
    imported_at    TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS to_norms (
    id          SERIAL PRIMARY KEY,
    matrix_id   INT NOT NULL REFERENCES to_matrices(id) ON DELETE CASCADE,
    to_code     VARCHAR(20) NOT NULL,
    norm_hours  NUMERIC(10,3),
    UNIQUE(matrix_id, to_code)
);

CREATE TABLE IF NOT EXISTS to_items (
    id          SERIAL PRIMARY KEY,
    matrix_id   INT NOT NULL REFERENCES to_matrices(id) ON DELETE CASCADE,
    row_num     INT,
    name        VARCHAR(500) NOT NULL,
    article     VARCHAR(200),
    unit        VARCHAR(50),
    quantities  JSONB NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_to_items_matrix ON to_items(matrix_id);
CREATE INDEX IF NOT EXISTS idx_to_items_qty    ON to_items USING GIN (quantities);
