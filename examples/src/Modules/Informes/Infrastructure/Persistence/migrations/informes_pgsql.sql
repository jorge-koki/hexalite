-- Esquema del módulo Informes (PostgreSQL). Idempotente.
CREATE TABLE IF NOT EXISTS informes (
    id           BIGSERIAL PRIMARY KEY,
    titulo       VARCHAR(150) NOT NULL,
    id_proyecto  BIGINT       NOT NULL,
    estado       VARCHAR(20)  NOT NULL DEFAULT 'borrador',
    publicado_en TIMESTAMP    NULL,
    CONSTRAINT informes_estado_check CHECK (estado IN ('borrador', 'publicado'))
);

CREATE INDEX IF NOT EXISTS informes_id_proyecto_idx ON informes (id_proyecto);
