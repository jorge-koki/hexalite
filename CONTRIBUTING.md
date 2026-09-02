# Guía de contribución

¡Gracias por tu interés en mejorar HexaLite! Esta guía resume cómo colaborar.

## Requisitos

- PHP **8.2** o superior
- [Composer](https://getcomposer.org/)

## Puesta en marcha

```bash
git clone https://github.com/jorge-koki/hexalite.git
cd hexalite
composer install
```

## Ejecutar las pruebas

```bash
composer test
```

Para ver cobertura (requiere Xdebug o PCOV):

```bash
composer test:coverage
```

## Estándares de código

- Sigue **PSR-12** y respeta el `.editorconfig` del repositorio.
- Todo el código de librería vive bajo `src/` (namespace `HexaLite\`).
- Cada cambio de comportamiento debe venir acompañado de **pruebas**.
- Mantén el núcleo **sin dependencias pesadas**: si necesitas integrar una
  librería de terceros, evalúa si puede ir en `suggest` y detrás de un
  `class_exists()`/hook en vez de un `require` obligatorio.

## Flujo de trabajo

1. Crea una rama descriptiva (`feat/…`, `fix/…`).
2. Añade pruebas y asegúrate de que `composer test` pasa en verde.
3. Actualiza el `CHANGELOG.md` (sección _Unreleased_).
4. Abre un Pull Request explicando el _qué_ y el _porqué_.

## Reporte de vulnerabilidades

Si encuentras un problema de seguridad, **no** abras un issue público: contacta
en privado al mantenedor para coordinar una divulgación responsable.
