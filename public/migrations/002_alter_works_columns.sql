-- Расширяем поля — реальные коды из 1С бывают длиннее 20 символов
ALTER TABLE work_operations ALTER COLUMN code          TYPE VARCHAR(255);
ALTER TABLE work_operations ALTER COLUMN parent_code   TYPE VARCHAR(255);
ALTER TABLE work_operations ALTER COLUMN operation_code TYPE VARCHAR(255);

-- На всякий случай и в номенклатуре
ALTER TABLE nomenclatures ALTER COLUMN code     TYPE VARCHAR(255);
ALTER TABLE nomenclatures ALTER COLUMN code_1c  TYPE VARCHAR(255);
ALTER TABLE nomenclatures ALTER COLUMN parent   TYPE VARCHAR(500);
