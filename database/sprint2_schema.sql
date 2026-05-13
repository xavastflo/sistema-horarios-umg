-- =============================================================================
-- SISTEMA DE HORARIOS UNIVERSITARIOS
-- Script SQL Sprint 2 — tablas nuevas
-- Ejecutar DESPUÉS de sprint1_schema_v2.sql
-- MySQL 8.0+ | utf8mb4_general_ci
-- =============================================================================

USE horarios_universitarios;
SET FOREIGN_KEY_CHECKS = 0;

-- ── PERIODO_ACADEMICO ─────────────────────────────────────────────────────────
-- NOTA: No declara ENGINE en el SQL oficial (sin InnoDB explícito)
DROP TABLE IF EXISTS periodo_academico;
CREATE TABLE periodo_academico (
    id_periodo_academico            int(10) UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre_periodo                  varchar(100)        NOT NULL,
    anio                            year(4)             NOT NULL,
    numero_periodo                  tinyint(3) UNSIGNED NOT NULL,
    fecha_inicio                    date                NOT NULL,
    fecha_fin                       date                NOT NULL,
    fecha_limite_edicion_horarios   datetime            DEFAULT NULL,
    -- ENUM de 4 estados propios — diferente al activo/inactivo de otras tablas
    estado                          enum('planificacion','activo','cerrado','finalizado')
                                    NOT NULL DEFAULT 'planificacion',
    es_vigente                      tinyint(1)          NOT NULL DEFAULT 0,
    fecha_creacion                  datetime            NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion             datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_periodo_academico),
    UNIQUE KEY uq_periodo_anio_numero (anio, numero_periodo)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── CURSO ─────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS curso;
CREATE TABLE curso (
    id_curso            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo_curso        varchar(20)      NOT NULL,
    nombre_curso        varchar(120)     NOT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_curso),
    UNIQUE KEY codigo_curso (codigo_curso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── PENSUM ────────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS pensum;
CREATE TABLE pensum (
    id_pensum           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_carrera          int(10) UNSIGNED NOT NULL,
    id_periodo_academico int(10) UNSIGNED NOT NULL,
    nombre_pensum       varchar(120)     NOT NULL,
    codigo_pensum       varchar(20)      NOT NULL,
    descripcion         varchar(200)     DEFAULT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_pensum),
    UNIQUE KEY codigo_pensum (codigo_pensum),
    KEY fk_pensum_carrera (id_carrera),
    KEY fk_pensum_periodo (id_periodo_academico),

    CONSTRAINT fk_pensum_carrera
        FOREIGN KEY (id_carrera) REFERENCES carrera (id_carrera),
    CONSTRAINT fk_pensum_periodo
        FOREIGN KEY (id_periodo_academico) REFERENCES periodo_academico (id_periodo_academico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── PENSUM_CURSO ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS pensum_curso;
CREATE TABLE pensum_curso (
    id_pensum_curso int(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
    id_pensum       int(10) UNSIGNED     NOT NULL,
    id_curso        int(10) UNSIGNED     NOT NULL,
    ciclo_semestre  tinyint(3) UNSIGNED  NOT NULL,
    estado          enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion  datetime             NOT NULL DEFAULT current_timestamp(),

    PRIMARY KEY (id_pensum_curso),
    UNIQUE KEY uq_pensum_curso (id_pensum, id_curso),
    KEY fk_pensum_curso_curso (id_curso),

    CONSTRAINT fk_pensum_curso_pensum
        FOREIGN KEY (id_pensum) REFERENCES pensum (id_pensum),
    CONSTRAINT fk_pensum_curso_curso
        FOREIGN KEY (id_curso) REFERENCES curso (id_curso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── BLOQUE_HORARIO ────────────────────────────────────────────────────────────
-- NOTA: No declara ENGINE en el SQL oficial
DROP TABLE IF EXISTS bloque_horario;
CREATE TABLE bloque_horario (
    id_bloque_horario   int(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
    id_carrera_jornada  int(10) UNSIGNED     NOT NULL,
    id_dia              tinyint(3) UNSIGNED  NOT NULL,
    hora_inicio         time                 NOT NULL,
    hora_fin            time                 NOT NULL,
    duracion_minutos    smallint(5) UNSIGNED NOT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime             NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_bloque_horario),
    UNIQUE KEY uq_bloque_horario (id_carrera_jornada, id_dia, hora_inicio, hora_fin),
    KEY fk_bloque_dia (id_dia),

    CONSTRAINT fk_bloque_carrera_jornada
        FOREIGN KEY (id_carrera_jornada) REFERENCES carrera_jornada (id_carrera_jornada),
    CONSTRAINT fk_bloque_dia
        FOREIGN KEY (id_dia) REFERENCES dia (id_dia)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── DISPONIBILIDAD_DOCENTE ────────────────────────────────────────────────────
-- REGLA: Un registro = docente NO disponible en ese bloque.
-- Si no existe registro, el docente SÍ está disponible.
-- Sin campo tipo_disponibilidad (no existe en SQL oficial).
DROP TABLE IF EXISTS disponibilidad_docente;
CREATE TABLE disponibilidad_docente (
    id_disponibilidad_docente   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_docente                  int(10) UNSIGNED NOT NULL,
    id_bloque_horario           int(10) UNSIGNED NOT NULL,
    observacion                 varchar(200)     DEFAULT NULL,
    estado                      enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    -- Campo se llama fecha_registro (no fecha_creacion) según SQL oficial
    fecha_registro              datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_disponibilidad_docente),
    UNIQUE KEY uq_disponibilidad_docente_bloque (id_docente, id_bloque_horario),
    KEY fk_disponibilidad_bloque (id_bloque_horario),

    CONSTRAINT fk_disponibilidad_docente
        FOREIGN KEY (id_docente) REFERENCES docente (id_docente),
    CONSTRAINT fk_disponibilidad_bloque
        FOREIGN KEY (id_bloque_horario) REFERENCES bloque_horario (id_bloque_horario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── SECCION ───────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS seccion;
CREATE TABLE seccion (
    id_seccion          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_curso            int(10) UNSIGNED NOT NULL,
    id_periodo_academico int(10) UNSIGNED NOT NULL,
    numero_seccion      varchar(10)      NOT NULL,
    estado              enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_creacion      datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_seccion),
    UNIQUE KEY uq_seccion (id_curso, id_periodo_academico, numero_seccion),
    KEY fk_seccion_periodo (id_periodo_academico),

    CONSTRAINT fk_seccion_curso
        FOREIGN KEY (id_curso) REFERENCES curso (id_curso),
    CONSTRAINT fk_seccion_periodo
        FOREIGN KEY (id_periodo_academico) REFERENCES periodo_academico (id_periodo_academico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── ASIGNACION_DOCENTE_CURSO ──────────────────────────────────────────────────
-- UNIQUE(id_seccion): garantiza que una sección tenga SOLO UN docente activo.
DROP TABLE IF EXISTS asignacion_docente_curso;
CREATE TABLE asignacion_docente_curso (
    id_asignacion_docente_curso int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    id_docente                  int(10) UNSIGNED NOT NULL,
    id_seccion                  int(10) UNSIGNED NOT NULL,
    estado                      enum('activo','inactivo') NOT NULL DEFAULT 'activo',
    fecha_asignacion            datetime         NOT NULL DEFAULT current_timestamp(),
    fecha_actualizacion         datetime         NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

    PRIMARY KEY (id_asignacion_docente_curso),
    UNIQUE KEY id_seccion (id_seccion),
    KEY fk_asignacion_docente (id_docente),

    CONSTRAINT fk_asignacion_docente
        FOREIGN KEY (id_docente) REFERENCES docente (id_docente),
    CONSTRAINT fk_asignacion_seccion
        FOREIGN KEY (id_seccion) REFERENCES seccion (id_seccion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- VERIFICACIÓN
-- =============================================================================
SELECT '==============================' AS '';
SELECT 'SPRINT 2 — INSTALACIÓN COMPLETA' AS '';
SELECT '==============================' AS '';
SELECT CONCAT('periodo_academico:       ', COUNT(*)) FROM periodo_academico;
SELECT CONCAT('curso:                   ', COUNT(*)) FROM curso;
SELECT CONCAT('pensum:                  ', COUNT(*)) FROM pensum;
SELECT CONCAT('pensum_curso:            ', COUNT(*)) FROM pensum_curso;
SELECT CONCAT('bloque_horario:          ', COUNT(*)) FROM bloque_horario;
SELECT CONCAT('disponibilidad_docente:  ', COUNT(*)) FROM disponibilidad_docente;
SELECT CONCAT('seccion:                 ', COUNT(*)) FROM seccion;
SELECT CONCAT('asignacion_docente_curso:', COUNT(*)) FROM asignacion_docente_curso;
