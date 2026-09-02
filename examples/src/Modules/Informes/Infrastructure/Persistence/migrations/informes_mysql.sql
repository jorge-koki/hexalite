-- Esquema del módulo Informes (MySQL / MariaDB). Idempotente.
CREATE TABLE IF NOT EXISTS informes (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    titulo       VARCHAR(150) NOT NULL,
    id_proyecto  BIGINT       NOT NULL,
    estado       VARCHAR(20)  NOT NULL DEFAULT 'borrador',
    publicado_en DATETIME     NULL,
    INDEX informes_id_proyecto_idx (id_proyecto),
    CONSTRAINT informes_estado_check CHECK (estado IN ('borrador', 'publicado'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
