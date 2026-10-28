-- Second database for the integration suites, which reset it between runs.
CREATE DATABASE IF NOT EXISTS yas_test_integration;
GRANT ALL PRIVILEGES ON yas_test_integration.* TO 'yas'@'%';
FLUSH PRIVILEGES;
