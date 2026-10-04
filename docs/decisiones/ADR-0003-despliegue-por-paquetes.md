# ADR-0003 — Despliegue por paquetes desde GitHub

**Estado:** aceptada · **Fecha:** 2026-10-04

## Contexto

El hosting (Neubox cPanel) no tiene SSH ni "Git Version Control", PHP web tiene deshabilitadas `proc_open`/`exec` y el cron sí puede ejecutar scripts de shell. Se necesitan dos ambientes (QA y Producción) con aprobación por versión y regreso rápido.

## Decisión

- GitHub Actions arma un paquete con el código y las librerías de producción, y lo publica en *Releases*: `qa` para `develop` y `vX.Y.Z` para Producción.
- En el servidor, `desplegar.sh` (cron cada 5 min) descarga el paquete con un token de solo lectura, verifica la huella sha256 e instala en `releases/` con un acceso directo `actual` (instalación sin cortes, se conservan 3 versiones).
- Producción solo instala la versión escrita en `desplegar_version.txt`: escribir el archivo es la autorización.
- Después de instalar se consulta `/up`; si falla, se regresa sola a la versión anterior.

## Consecuencias

- No se ejecuta Composer en el servidor (era lento y requería levantar `disable_functions`).
- Lo que se instala es exactamente lo que pasó la CI.
- Las migraciones deben ser compatibles con la versión anterior, porque un regreso no revierte la base.
- El token de GitHub caduca: hay que renovarlo antes de su vencimiento (máximo 1 año).
