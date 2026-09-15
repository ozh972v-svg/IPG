-- Справочник работ из 1С:ГОА
CREATE TABLE IF NOT EXISTS work_operations (
    code            VARCHAR(9) PRIMARY KEY,
    parent_code     VARCHAR(9),
    it_is_group     BOOLEAN NOT NULL DEFAULT FALSE,
    name            VARCHAR(150),
    operation_code  VARCHAR(20),
    name_work       VARCHAR(150),
    eng_name        VARCHAR(150),
    description     TEXT,
    guard_work      BOOLEAN NOT NULL DEFAULT FALSE,
    fact_work       BOOLEAN NOT NULL DEFAULT FALSE,
    deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_work_parent  ON work_operations(parent_code);
CREATE INDEX IF NOT EXISTS idx_work_group   ON work_operations(it_is_group);
CREATE INDEX IF NOT EXISTS idx_work_deleted ON work_operations(deleted);

-- Справочник номенклатуры из 1С:ГОА
CREATE TABLE IF NOT EXISTS nomenclatures (
    code            VARCHAR(31) PRIMARY KEY,
    name            VARCHAR(150),
    full_name       VARCHAR(1000),
    eng_name        VARCHAR(150),
    base_measure    VARCHAR(50),
    code_1c         VARCHAR(9),
    parent          VARCHAR(150),
    deleted         BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_nom_parent  ON nomenclatures(parent);
CREATE INDEX IF NOT EXISTS idx_nom_deleted ON nomenclatures(deleted);

-- Журнал синхронизаций
CREATE TABLE IF NOT EXISTS sync_log (
    id              SERIAL PRIMARY KEY,
    sync_type       VARCHAR(50) NOT NULL,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    finished_at     TIMESTAMPTZ,
    status          VARCHAR(20) NOT NULL DEFAULT 'running',
    items_total     INTEGER DEFAULT 0,
    items_updated   INTEGER DEFAULT 0,
    error_message   TEXT
);
CREATE INDEX IF NOT EXISTS idx_sync_log_type ON sync_log(sync_type, started_at DESC);
