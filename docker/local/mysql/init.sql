-- Create databases for Laravel application and Shopware test data
CREATE DATABASE IF NOT EXISTS `migrator_app` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `migrator_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions
GRANT ALL PRIVILEGES ON `migrator_app`.* TO 'root'@'%';
GRANT ALL PRIVILEGES ON `migrator_test`.* TO 'root'@'%';
GRANT ALL PRIVILEGES ON `migrator_app`.* TO 'migrator'@'%';
GRANT ALL PRIVILEGES ON `migrator_test`.* TO 'migrator'@'%';

FLUSH PRIVILEGES;
