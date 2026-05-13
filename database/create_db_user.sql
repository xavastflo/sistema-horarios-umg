-- =============================================================================
-- CONFIGURACIÓN DE USUARIO MySQL PARA EL SISTEMA
-- Ejecutar como root: mysql -u root -p < create_db_user.sql
-- =============================================================================

-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS horarios_universitarios
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Crear usuario con permisos mínimos (principio de menor privilegio)
CREATE USER IF NOT EXISTS 'horarios_user'@'localhost'
    IDENTIFIED BY 'CambiarEstaPassword123!';

-- Otorgar permisos solo sobre la base del sistema
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER, REFERENCES
    ON horarios_universitarios.*
    TO 'horarios_user'@'localhost';

FLUSH PRIVILEGES;

SELECT 'Usuario horarios_user creado con permisos sobre horarios_universitarios' AS resultado;
