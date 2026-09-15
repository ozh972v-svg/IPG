-- Расширяем поля — реальные коды из 1С бывают длиннее 20 символов
ALTER TABLE work_operations ALTER COLUMN code           TYPE VARCHAR(50);
ALTER TABLE work_operations ALTER COLUMN parent_code    TYPE VARCHAR(50);
ALTER TABLE work_operations ALTER COLUMN operation_code TYPE VARCHAR(50);
ALTER TABLE work_operations ALTER COLUMN name           TYPE VARCHAR(250);
ALTER TABLE work_operations ALTER COLUMN name_work      TYPE VARCHAR(250);
ALTER TABLE work_operations ALTER COLUMN eng_name       TYPE VARCHAR(250);

-- Номенклатура
ALTER TABLE nomenclatures ALTER COLUMN code     TYPE VARCHAR(255);
ALTER TABLE nomenclatures ALTER COLUMN code_1c  TYPE VARCHAR(255);
ALTER TABLE nomenclatures ALTER COLUMN parent   TYPE VARCHAR(500);
