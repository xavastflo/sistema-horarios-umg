-- =============================================================================
-- SISTEMA DE HORARIOS UNIVERSITARIOS
-- Script SQL completo - Sprint 1
-- MySQL 8.0+
-- =============================================================================
-- Uso: mysql -u root -p < sprint1_schema.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS horarios_universitarios
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE horarios_universitarios;

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- =============================================================================
-- CATÁLOGOS BASE (sin dependencias)
-- =============================================================================

-- ── ROL ───────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS rol;
CREATE TABLE rol (
    id_rol          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_rol      VARCHAR(50)     NOT NULL,
    descripcion     VARCHAR(255)    NULL,
    estado          TINYINT         NOT NULL DEFAULT 1     COMMENT '1=activo, 0=inactivo',
    fecha_creacion  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id_rol),
    UNIQUE KEY uq_rol_nombre (nombre_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DIA ───────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS dia;
CREATE TABLE dia (
    id_dia          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_dia      VARCHAR(20)     NOT NULL,
    orden_semana    TINYINT UNSIGNED NOT NULL               COMMENT '1=Lunes, 7=Domingo',
    estado          TINYINT         NOT NULL DEFAULT 1     COMMENT '1=activo, 0=inactivo',

    PRIMARY KEY (id_dia),
    UNIQUE KEY uq_dia_nombre (nombre_dia),
    UNIQUE KEY uq_dia_orden  (orden_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ESTADO_HORARIO ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS estado_horario;
CREATE TABLE estado_horario (
    id_estado_horario   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_estado       VARCHAR(50)     NOT NULL,
    descripcion         VARCHAR(255)    NULL,
    estado              TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',

    PRIMARY KEY (id_estado_horario),
    UNIQUE KEY uq_estado_nombre (nombre_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── JORNADA ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS jornada;
CREATE TABLE jornada (
    id_jornada      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_jornada  VARCHAR(60)     NOT NULL,
    descripcion     VARCHAR(255)    NULL,
    estado          TINYINT         NOT NULL DEFAULT 1     COMMENT '1=activo, 0=inactivo',
    fecha_creacion  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id_jornada),
    UNIQUE KEY uq_jornada_nombre (nombre_jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- USUARIOS Y AUTENTICACIÓN
-- =============================================================================

-- ── USUARIO ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS usuario;
CREATE TABLE usuario (
    id_usuario                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombres                     VARCHAR(100)    NOT NULL,
    apellidos                   VARCHAR(100)    NOT NULL,
    nombre_usuario              VARCHAR(60)     NOT NULL,
    correo_electronico          VARCHAR(150)    NOT NULL,
    telefono                    VARCHAR(20)     NULL,
    password_hash               VARCHAR(255)    NOT NULL,
    pregunta_seguridad          VARCHAR(255)    NULL,
    respuesta_seguridad_hash    VARCHAR(255)    NULL,
    ultimo_acceso               TIMESTAMP       NULL,
    ultimo_perfil_activo        BIGINT UNSIGNED NULL       COMMENT 'FK a id_rol activo',
    estado                      TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_creacion              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id_usuario),
    UNIQUE KEY uq_usuario_nombre    (nombre_usuario),
    UNIQUE KEY uq_usuario_correo    (correo_electronico),
    KEY idx_usuario_estado          (estado),

    CONSTRAINT fk_usuario_perfil
        FOREIGN KEY (ultimo_perfil_activo) REFERENCES rol (id_rol)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── USUARIO_ROL ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS usuario_rol;
CREATE TABLE usuario_rol (
    id_usuario_rol      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario          BIGINT UNSIGNED NOT NULL,
    id_rol              BIGINT UNSIGNED NOT NULL,
    estado              TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_asignacion    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_desasignacion TIMESTAMP       NULL,

    PRIMARY KEY (id_usuario_rol),
    UNIQUE KEY uq_usuario_rol (id_usuario, id_rol),
    KEY idx_ur_usuario (id_usuario),
    KEY idx_ur_rol     (id_rol),

    CONSTRAINT fk_ur_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ur_rol
        FOREIGN KEY (id_rol) REFERENCES rol (id_rol)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PERSONAL_ACCESS_TOKENS (Sanctum) ─────────────────────────────────────────
DROP TABLE IF EXISTS personal_access_tokens;
CREATE TABLE personal_access_tokens (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tokenable_type  VARCHAR(255)    NOT NULL,
    tokenable_id    BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    token           VARCHAR(64)     NOT NULL,
    abilities       TEXT            NULL,
    last_used_at    TIMESTAMP       NULL,
    expires_at      TIMESTAMP       NULL,
    created_at      TIMESTAMP       NULL,
    updated_at      TIMESTAMP       NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ESTRUCTURA ACADÉMICA
-- =============================================================================

-- ── FACULTAD ──────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS facultad;
CREATE TABLE facultad (
    id_facultad         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_facultad     VARCHAR(150)    NOT NULL,
    codigo_facultad     VARCHAR(20)     NOT NULL,
    descripcion         VARCHAR(255)    NULL,
    estado              TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_creacion      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id_facultad),
    UNIQUE KEY uq_facultad_nombre (nombre_facultad),
    UNIQUE KEY uq_facultad_codigo (codigo_facultad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CARRERA ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS carrera;
CREATE TABLE carrera (
    id_carrera                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_facultad                     BIGINT UNSIGNED NOT NULL,
    nombre_carrera                  VARCHAR(150)    NOT NULL,
    codigo_carrera                  VARCHAR(20)     NOT NULL,
    id_usuario_coordinador          BIGINT UNSIGNED NULL       COMMENT 'Coordinador activo asignado',
    estado                          TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_asignacion_coordinador    TIMESTAMP       NULL,
    fecha_desasignacion_coordinador TIMESTAMP       NULL,
    fecha_creacion                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id_carrera),
    UNIQUE KEY uq_carrera_codigo (codigo_carrera),
    KEY idx_carrera_facultad    (id_facultad),
    KEY idx_carrera_coordinador (id_usuario_coordinador),
    KEY idx_carrera_estado      (estado),

    CONSTRAINT fk_carrera_facultad
        FOREIGN KEY (id_facultad) REFERENCES facultad (id_facultad)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_carrera_coordinador
        FOREIGN KEY (id_usuario_coordinador) REFERENCES usuario (id_usuario)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CARRERA_JORNADA ───────────────────────────────────────────────────────────
-- (Se crea aquí para Sprint 1 aunque se usa más en Sprint 2)
DROP TABLE IF EXISTS carrera_jornada;
CREATE TABLE carrera_jornada (
    id_carrera_jornada  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_carrera          BIGINT UNSIGNED NOT NULL,
    id_jornada          BIGINT UNSIGNED NOT NULL,
    estado              TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_creacion      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id_carrera_jornada),
    UNIQUE KEY uq_carrera_jornada (id_carrera, id_jornada),
    KEY idx_cj_carrera  (id_carrera),
    KEY idx_cj_jornada  (id_jornada),

    CONSTRAINT fk_cj_carrera
        FOREIGN KEY (id_carrera) REFERENCES carrera (id_carrera)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cj_jornada
        FOREIGN KEY (id_jornada) REFERENCES jornada (id_jornada)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DOCENTES
-- =============================================================================

-- ── DOCENTE ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS docente;
CREATE TABLE docente (
    id_docente          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario          BIGINT UNSIGNED NOT NULL,
    codigo_docente      VARCHAR(30)     NOT NULL,
    prioridad           TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=alta, 2=media, 3=baja',
    estado              TINYINT         NOT NULL DEFAULT 1  COMMENT '1=activo, 0=inactivo',
    fecha_creacion      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id_docente),
    UNIQUE KEY uq_docente_usuario (id_usuario),
    UNIQUE KEY uq_docente_codigo  (codigo_docente),
    KEY idx_docente_prioridad     (prioridad),
    KEY idx_docente_estado        (estado),

    CONSTRAINT fk_docente_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
        ON DELETE RESTRICT ON UPDATE CASCADE,

    -- Solo permite prioridad 1, 2 o 3
    CONSTRAINT chk_docente_prioridad CHECK (prioridad IN (1, 2, 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- AUDITORÍA
-- =============================================================================

-- ── HISTORIAL_CAMBIOS ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS historial_cambios;
CREATE TABLE historial_cambios (
    id_historial_cambios    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario              BIGINT UNSIGNED NULL       COMMENT 'NULL si fue acción del sistema',
    tabla_afectada          VARCHAR(80)     NOT NULL,
    id_registro_afectado    BIGINT UNSIGNED NOT NULL,
    tipo_cambio             ENUM(
                                'insert',
                                'update',
                                'delete',
                                'aprobacion',
                                'bloqueo',
                                'duplicacion',
                                'asignacion'
                            ) NOT NULL,
    valor_anterior          JSON            NULL,
    valor_nuevo             JSON            NULL,
    motivo_cambio           VARCHAR(255)    NULL,
    fecha_cambio            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id_historial_cambios),
    KEY idx_hc_tabla_registro   (tabla_afectada, id_registro_afectado),
    KEY idx_hc_usuario          (id_usuario),
    KEY idx_hc_fecha            (fecha_cambio),
    KEY idx_hc_tipo             (tipo_cambio),

    CONSTRAINT fk_hc_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- DATOS INICIALES (SEEDERS)
-- =============================================================================

-- ── ROLES ─────────────────────────────────────────────────────────────────────
INSERT INTO rol (nombre_rol, descripcion, estado) VALUES
    ('administrador', 'Acceso total al sistema',                              1),
    ('coordinador',   'Gestiona carreras, pensums y horarios',                1),
    ('docente',       'Registra disponibilidad y consulta horarios',          1),
    ('estudiante',    'Consulta horarios publicados',                         1)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ── JORNADAS ──────────────────────────────────────────────────────────────────
INSERT INTO jornada (nombre_jornada, descripcion, estado) VALUES
    ('matutina',      'Horario de mañana',       1),
    ('vespertina',    'Horario de tarde-noche',   1),
    ('fin_de_semana', 'Sábados y domingos',       1)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ── DÍAS ──────────────────────────────────────────────────────────────────────
INSERT INTO dia (id_dia, nombre_dia, orden_semana, estado) VALUES
    (1, 'Lunes',     1, 1),
    (2, 'Martes',    2, 1),
    (3, 'Miércoles', 3, 1),
    (4, 'Jueves',    4, 1),
    (5, 'Viernes',   5, 1),
    (6, 'Sábado',    6, 1),
    (7, 'Domingo',   7, 1)
ON DUPLICATE KEY UPDATE nombre_dia = VALUES(nombre_dia), orden_semana = VALUES(orden_semana);

-- ── ESTADOS DE HORARIO ────────────────────────────────────────────────────────
INSERT INTO estado_horario (nombre_estado, descripcion, estado) VALUES
    ('borrador',   'En proceso de construcción, editable',                 1),
    ('generado',   'Generado automáticamente, pendiente de revisión',      1),
    ('aprobado',   'Aprobado por administrador',                           1),
    ('bloqueado',  'Bloqueado, no se puede modificar',                     1),
    ('publicado',  'Publicado, visible para estudiantes y docentes',       1)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ── USUARIO ADMINISTRADOR INICIAL ─────────────────────────────────────────────
-- Password: Admin@2024!  (bcrypt hash generado con cost=12)
-- ⚠ CAMBIAR EN PRODUCCIÓN
INSERT INTO usuario (
    nombres, apellidos, nombre_usuario, correo_electronico,
    password_hash, pregunta_seguridad, respuesta_seguridad_hash,
    ultimo_perfil_activo, estado
) VALUES (
    'Administrador',
    'Sistema',
    'admin',
    'admin@universidad.edu',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '¿Cuál es el nombre del sistema?',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    (SELECT id_rol FROM rol WHERE nombre_rol = 'administrador' LIMIT 1),
    1
) ON DUPLICATE KEY UPDATE estado = 1;

-- Asignar rol administrador al usuario admin
INSERT INTO usuario_rol (id_usuario, id_rol, estado, fecha_asignacion)
SELECT
    u.id_usuario,
    r.id_rol,
    1,
    CURRENT_TIMESTAMP
FROM usuario u, rol r
WHERE u.nombre_usuario = 'admin'
  AND r.nombre_rol = 'administrador'
ON DUPLICATE KEY UPDATE estado = 1, fecha_asignacion = CURRENT_TIMESTAMP;

-- =============================================================================
-- VERIFICACIÓN FINAL
-- =============================================================================
SELECT 'INSTALACIÓN SPRINT 1 COMPLETADA' AS mensaje;
SELECT CONCAT('Roles creados: ', COUNT(*)) AS roles FROM rol;
SELECT CONCAT('Jornadas creadas: ', COUNT(*)) AS jornadas FROM jornada;
SELECT CONCAT('Días creados: ', COUNT(*)) AS dias FROM dia;
SELECT CONCAT('Estados horario creados: ', COUNT(*)) AS estados FROM estado_horario;
SELECT CONCAT('Usuario admin: ', nombre_usuario, ' | correo: ', correo_electronico) AS admin
FROM usuario WHERE nombre_usuario = 'admin';
