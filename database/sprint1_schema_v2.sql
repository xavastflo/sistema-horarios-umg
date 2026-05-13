-- =============================================================================
-- SISTEMA DE HORARIOS UNIVERSITARIOS
-- Script SQL Sprint 1 — VERSIÓN CORREGIDA
-- Alineado al 100% con sistema_horarios_umg.sql oficial
-- MySQL 8.0+ / MariaDB 10.4+
-- =============================================================================
-- Uso: mysql -u horarios_user -p horarios_universitarios < sprint1_schema_v2.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS horarios_universitarios
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;   -- collation del SQL oficial

USE horarios_universitarios;

SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- =============================================================================
-- CATÁLOGOS BASE (sin dependencias)
-- =============================================================================

-- ── ROL ───────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS rol;
CREATE TABLE rol (
    id_rol          tinyint(3) UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre_rol      varchar(30)          NOT NULL,
    descripcion     varchar(150)         DEFAULT NULL,
    estado          enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion  datetime             NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id_rol),
    UNIQUE KEY nombre_rol (nombre_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── DIA ───────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS dia;
CREATE TABLE dia (
    id_dia          tinyint(3) UNSIGNED  NOT NULL,
    nombre_dia      varchar(15)          NOT NULL,
    orden_semana    tinyint(3) UNSIGNED  NOT NULL,
    estado          enum('activo','inactivo') NOT NULL DEFAULT 'activo',

    PRIMARY KEY (id_dia),
    UNIQUE KEY nombre_dia (nombre_dia),
    UNIQUE KEY orden_semana (orden_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── ESTADO_HORARIO ────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS estado_horario;
CREATE TABLE estado_horario (
    id_estado_horario   tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_estado       varchar(30)         NOT NULL,
    descripcion         varchar(150)        DEFAULT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',

    PRIMARY KEY (id_estado_horario),
    UNIQUE KEY nombre_estado (nombre_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── JORNADA ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS jornada;
CREATE TABLE jornada (
    id_jornada      tinyint(3) UNSIGNED  NOT NULL AUTO_INCREMENT,
    nombre_jornada  varchar(50)          NOT NULL,
    descripcion     varchar(150)         DEFAULT NULL,
    estado          enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion  datetime             NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id_jornada),
    UNIQUE KEY nombre_jornada (nombre_jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- USUARIOS Y AUTENTICACIÓN
-- =============================================================================

-- ── USUARIO ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS usuario;
CREATE TABLE usuario (
    id_usuario                  int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombres                     varchar(100)        NOT NULL,
    apellidos                   varchar(100)        NOT NULL,
    nombre_usuario              varchar(50)         NOT NULL,
    correo_electronico          varchar(120)        NOT NULL,
    telefono                    varchar(20)         DEFAULT NULL,
    password_hash               varchar(255)        NOT NULL,
    -- NOT NULL en SQL oficial: obligatorios al crear usuario
    pregunta_seguridad          varchar(150)        NOT NULL,
    respuesta_seguridad_hash    varchar(255)        NOT NULL,
    ultimo_acceso               datetime            DEFAULT NULL,
    -- varchar(100): guarda el nombre del rol como texto, NO es FK
    ultimo_perfil_activo        varchar(100)        DEFAULT NULL,
    -- ENUM de 3 valores: 'bloqueado' permite bloquear sin eliminar
    estado                      enum('activo','inactivo','bloqueado') NOT NULL DEFAULT 'activo',
    fecha_creacion              datetime            NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion         datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_usuario),
    UNIQUE KEY nombre_usuario (nombre_usuario),
    UNIQUE KEY correo_electronico (correo_electronico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── USUARIO_ROL ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS usuario_rol;
CREATE TABLE usuario_rol (
    id_usuario_rol      int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_usuario          int(10) UNSIGNED    NOT NULL,
    id_rol              tinyint(3) UNSIGNED NOT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_asignacion    datetime            NOT NULL DEFAULT current_timestamp(),
    fecha_desasignacion datetime            DEFAULT NULL,

    PRIMARY KEY (id_usuario_rol),
    UNIQUE KEY uq_usuario_rol (id_usuario, id_rol),
    KEY fk_usuario_rol_rol (id_rol),

    CONSTRAINT fk_usuario_rol_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario),
    CONSTRAINT fk_usuario_rol_rol
        FOREIGN KEY (id_rol) REFERENCES rol (id_rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── PERSONAL_ACCESS_TOKENS (Sanctum) ─────────────────────────────────────────
DROP TABLE IF EXISTS personal_access_tokens;
CREATE TABLE personal_access_tokens (
    id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    tokenable_type  varchar(255)        NOT NULL,
    tokenable_id    bigint(20) UNSIGNED NOT NULL,
    name            varchar(255)        NOT NULL,
    token           varchar(64)         NOT NULL,
    abilities       text                DEFAULT NULL,
    last_used_at    timestamp           NULL,
    expires_at      timestamp           NULL,
    created_at      timestamp           NULL,
    updated_at      timestamp           NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- ESTRUCTURA ACADÉMICA
-- =============================================================================

-- ── FACULTAD ──────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS facultad;
CREATE TABLE facultad (
    id_facultad         smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_facultad     varchar(100)         NOT NULL,
    -- nullable en SQL oficial
    codigo_facultad     varchar(20)          DEFAULT NULL,
    descripcion         varchar(200)         DEFAULT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime             NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_facultad),
    UNIQUE KEY nombre_facultad (nombre_facultad),
    UNIQUE KEY codigo_facultad (codigo_facultad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── CARRERA ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS carrera;
CREATE TABLE carrera (
    id_carrera                      int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_facultad                     smallint(5) UNSIGNED NOT NULL,
    nombre_carrera                  varchar(120)        NOT NULL,
    codigo_carrera                  varchar(20)         NOT NULL,
    id_usuario_coordinador          int(10) UNSIGNED    DEFAULT NULL,
    estado                          enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_asignacion_coordinador    datetime            DEFAULT NULL,
    fecha_desasignacion_coordinador datetime            DEFAULT NULL,
    fecha_creacion                  datetime            NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion             datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_carrera),
    UNIQUE KEY codigo_carrera (codigo_carrera),
    KEY fk_carrera_facultad (id_facultad),
    KEY fk_carrera_coordinador (id_usuario_coordinador),

    CONSTRAINT fk_carrera_facultad
        FOREIGN KEY (id_facultad) REFERENCES facultad (id_facultad),
    CONSTRAINT fk_carrera_coordinador
        FOREIGN KEY (id_usuario_coordinador) REFERENCES usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── CARRERA_JORNADA ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS carrera_jornada;
CREATE TABLE carrera_jornada (
    id_carrera_jornada  int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
    id_carrera          int(10) UNSIGNED    NOT NULL,
    id_jornada          tinyint(3) UNSIGNED NOT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime            NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id_carrera_jornada),
    UNIQUE KEY uq_carrera_jornada (id_carrera, id_jornada),
    KEY fk_carrera_jornada_jornada (id_jornada),

    CONSTRAINT fk_carrera_jornada_carrera
        FOREIGN KEY (id_carrera) REFERENCES carrera (id_carrera),
    CONSTRAINT fk_carrera_jornada_jornada
        FOREIGN KEY (id_jornada) REFERENCES jornada (id_jornada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- DOCENTES
-- =============================================================================

-- ── DOCENTE ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS docente;
CREATE TABLE docente (
    id_docente          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario          int(10) UNSIGNED NOT NULL,
    -- varchar(20) DEFAULT NULL: código opcional al crear
    codigo_docente      varchar(20)      DEFAULT NULL,
    -- int(11) DEFAULT 3: 1=alta, 2=media, 3=baja — validado por CHECK + aplicación
    prioridad           int(11)          NOT NULL DEFAULT 3,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_docente),
    UNIQUE KEY id_usuario (id_usuario),
    UNIQUE KEY codigo_docente (codigo_docente),
    KEY idx_prioridad (prioridad),

    CONSTRAINT fk_docente_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario),

    -- Solo permite prioridad 1, 2 o 3 (MySQL 8.0.16+ / MariaDB 10.4+)
    CONSTRAINT chk_docente_prioridad CHECK (prioridad IN (1, 2, 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- AUDITORÍA
-- =============================================================================

-- ── HISTORIAL_CAMBIOS ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS historial_cambios;
CREATE TABLE historial_cambios (
    id_historial_cambios    bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NOT NULL en SQL oficial: siempre debe tener usuario
    id_usuario              int(10) UNSIGNED    NOT NULL,
    tabla_afectada          varchar(100)        NOT NULL,
    id_registro_afectado    bigint(20) UNSIGNED NOT NULL,
    tipo_cambio             enum('insert','update','delete','aprobacion','bloqueo','duplicacion','asignacion') NOT NULL,
    -- text: JSON serializado como string
    valor_anterior          text                DEFAULT NULL,
    valor_nuevo             text                DEFAULT NULL,
    motivo_cambio           varchar(255)        DEFAULT NULL,
    fecha_cambio            datetime            NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id_historial_cambios),
    KEY fk_historial_usuario (id_usuario),
    KEY idx_hc_tabla_registro (tabla_afectada, id_registro_afectado),
    KEY idx_hc_fecha (fecha_cambio),

    CONSTRAINT fk_historial_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuario (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- DATOS INICIALES (SEEDERS)
-- =============================================================================

-- ── ROLES ─────────────────────────────────────────────────────────────────────
INSERT INTO rol (id_rol, nombre_rol, descripcion, estado) VALUES
    (1, 'administrador', 'Administrador del sistema',  'activo'),
    (2, 'coordinador',   'Coordinador académico',      'activo'),
    (3, 'docente',       'Docente del sistema',        'activo'),
    (4, 'estudiante',    'Estudiante',                 'activo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion), estado = VALUES(estado);

-- ── JORNADAS ──────────────────────────────────────────────────────────────────
INSERT INTO jornada (id_jornada, nombre_jornada, descripcion, estado) VALUES
    (1, 'matutina',      'Jornada matutina',    'activo'),
    (2, 'vespertina',    'Jornada vespertina',  'activo'),
    (3, 'fin_de_semana', 'Plan fin de semana',  'activo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ── DÍAS — minúsculas sin tilde, exacto al SQL oficial ────────────────────────
INSERT INTO dia (id_dia, nombre_dia, orden_semana, estado) VALUES
    (1, 'lunes',     1, 'activo'),
    (2, 'martes',    2, 'activo'),
    (3, 'miercoles', 3, 'activo'),
    (4, 'jueves',    4, 'activo'),
    (5, 'viernes',   5, 'activo'),
    (6, 'sabado',    6, 'activo'),
    (7, 'domingo',   7, 'activo')
ON DUPLICATE KEY UPDATE nombre_dia = VALUES(nombre_dia), orden_semana = VALUES(orden_semana);

-- ── ESTADOS DE HORARIO ────────────────────────────────────────────────────────
INSERT INTO estado_horario (id_estado_horario, nombre_estado, descripcion, estado) VALUES
    (1, 'borrador',  'Horario en creación o edición',        'activo'),
    (2, 'generado',  'Horario generado por el sistema',      'activo'),
    (3, 'aprobado',  'Horario aprobado por administración',  'activo'),
    (4, 'bloqueado', 'Horario bloqueado, no editable',       'activo'),
    (5, 'publicado', 'Horario visible para usuarios',        'activo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ── USUARIO ADMINISTRADOR INICIAL ─────────────────────────────────────────────
-- Password: Admin@2024!
-- Respuesta seguridad: horarios
-- ⚠ CAMBIAR EN PRODUCCIÓN
INSERT INTO usuario (
    nombres, apellidos, nombre_usuario, correo_electronico,
    password_hash,
    pregunta_seguridad, respuesta_seguridad_hash,
    ultimo_perfil_activo, estado
) VALUES (
    'Administrador',
    'Sistema',
    'admin',
    'admin@universidad.edu',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    '¿Cuál es el nombre del sistema?',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'administrador',   -- varchar(100): nombre del rol como texto
    'activo'           -- ENUM('activo','inactivo','bloqueado')
) ON DUPLICATE KEY UPDATE estado = 'activo';

-- Asignar rol administrador
INSERT INTO usuario_rol (id_usuario, id_rol, estado, fecha_asignacion)
SELECT u.id_usuario, r.id_rol, 'activo', NOW()
FROM usuario u, rol r
WHERE u.nombre_usuario = 'admin'
  AND r.nombre_rol = 'administrador'
ON DUPLICATE KEY UPDATE estado = 'activo', fecha_asignacion = NOW();

-- =============================================================================
-- VERIFICACIÓN FINAL
-- =============================================================================
SELECT '==========================================' AS '';
SELECT 'SPRINT 1 CORREGIDO — INSTALACIÓN COMPLETA' AS '';
SELECT '==========================================' AS '';
SELECT CONCAT('Roles:           ', COUNT(*)) AS resumen FROM rol;
SELECT CONCAT('Jornadas:        ', COUNT(*)) AS resumen FROM jornada;
SELECT CONCAT('Días:            ', COUNT(*)) AS resumen FROM dia;
SELECT CONCAT('Estados horario: ', COUNT(*)) AS resumen FROM estado_horario;
SELECT CONCAT('Usuario admin:   ', nombre_usuario, ' / estado: ', estado) AS resumen
    FROM usuario WHERE nombre_usuario = 'admin';
