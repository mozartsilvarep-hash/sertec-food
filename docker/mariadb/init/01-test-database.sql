-- Banco separado para a suíte de testes (RefreshDatabase).
CREATE DATABASE IF NOT EXISTS sertec_food_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sertec_food_test.* TO 'sertec'@'%';
FLUSH PRIVILEGES;
