-- Use only in a disposable database named emcore_boreholes_fixture.
CREATE TABLE USERS (
    USR_UID CHAR(32) PRIMARY KEY, USR_USERNAME VARCHAR(64), USR_FIRSTNAME VARCHAR(64),
    USR_LASTNAME VARCHAR(64), USR_ROLE VARCHAR(64), USR_STATUS VARCHAR(16)
);
INSERT INTO USERS VALUES
    ('00000000000000000000000000000001', 'admin', 'Fixture', 'Administrator', 'PROCESSMAKER_ADMIN', 'ACTIVE'),
    ('22222222222222222222222222222222', 'profile-reader', 'Fixture', 'Reader', 'PROCESSMAKER_OPERATOR', 'ACTIVE');

CREATE TABLE emcore_mines (id INT UNSIGNED PRIMARY KEY, mine_name VARCHAR(255), alias_name VARCHAR(255), deleted_at DATETIME NULL);
INSERT INTO emcore_mines VALUES (1, 'معدن یک', 'پروژه یک', NULL), (2, 'معدن دو', NULL, NULL), (3, 'معدن حذف‌شده', NULL, NOW());
CREATE TABLE emcore_persons (id INT UNSIGNED PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100), is_active TINYINT, deleted_at DATETIME NULL);
CREATE TABLE fixture_dates (fa VARCHAR(10) PRIMARY KEY, en DATE NOT NULL);
INSERT INTO fixture_dates VALUES ('1403/01/01', '2024-03-20'), ('1403/01/02', '2024-03-21'), ('1403/01/03', '2024-03-22');
CREATE FUNCTION shamsi_slash_to_gregorian_date(input_fa VARCHAR(10)) RETURNS DATE DETERMINISTIC READS SQL DATA
    RETURN (SELECT en FROM fixture_dates WHERE fa = input_fa);
