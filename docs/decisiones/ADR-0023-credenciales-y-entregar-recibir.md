# ADR-0023 — Lector de personas y empresas, credencialización y «Entregar / Recibir»

**Fecha:** 2026-11-02 · **Estado:** aceptada · **Detalle técnico:** [lector.md](../tecnico/lector.md), [credenciales.md](../tecnico/credenciales.md), [entregar-recibir.md](../tecnico/entregar-recibir.md)

## Contexto

Plan de correcciones, Etapa 8 (crecimiento). C-44: las personas del padrón y las empresas externas no se podían leer con QR o NFC, escanear a un colaborador o un vehículo solo abría su ficha, y la credencialización (aprobada) no existía. C-45 (DD-19, «sí, mediano plazo»): llave, gafete y equipo se entregan y reciben en tres pantallas con reglas parecidas pero no iguales. Regla del dueño: no perder ninguna funcionalidad de Préstamo de llaves, Gafetes ni Responsivas, y que el agente de caseta no gane pasos; lo nuevo, en «Avanzado» o Configuración.

## Decisión

1. **Persona y Proveedor son tipos del lector** (`codigo_qr`, `etiqueta_nfc`), con la misma regla única de tarjeta entre todos los tipos. El folio de la identificación de una persona no se busca (dato personal).
2. **Acción probable de la caseta** al escanear colaborador, vehículo, persona o empresa: «Dar salida» si está en sitio (la salida a la vista de siempre, con la revisión de gafete e identificación), «Nuevo ingreso» si no (reabre el registro con lo leído elegido; nada se guarda solo), o solo el aviso si tiene el acceso restringido.
3. **La credencial es un activo** con los estados de C-14 y su propia tabla (no es un tipo del lector: identifica al colaborador). Permiso nuevo `colaboradores.credencial`. La baja por pérdida genera voucher de origen «credencial» y cambia el QR y quita la tarjeta del colaborador; la baja del colaborador la da de baja sola.
4. **«Entregar / Recibir» en su versión mínima segura**: (a) historial único `movimientos_activo` que se escribe desde los eventos de los módulos dueños (sin cambiar sus flujos) y sin copiar nombres; (b) una recepción común OK / Dañado / No regresó que aceptan los endpoints de siempre (sin el campo, OK); (c) garantía de la llave y firma de la responsiva como ajustes por tipo, con el comportamiento original de fábrica.

## Alternativas descartadas

- **Una sola pantalla y una sola tabla de préstamos** para llaves, gafetes y equipos ahora: obligaba a migrar `prestamos_llaves`, `accesos.gafete_*` y `responsivas` y a rehacer sus impresiones y reportes; el riesgo de perder funcionalidad era alto. Queda para después (ver entregar-recibir.md § 4).
- **La credencial como tipo del lector con su propia tarjeta**: dos registros contestarían a la misma tarjeta (el colaborador y su credencial) y el lector no sabría cuál abrir.
- **Guardar el nombre del receptor en el historial**: duplicaba datos personales fuera del alcance de ARCO y de la conservación de cada módulo.

## Consecuencias

- Quien registra ingresos ya no va directo a la ficha al escanear a un colaborador: ve «Nuevo ingreso» o «Dar salida» y «Solo ver la ficha».
- El historial único empieza vacío; lo anterior sigue en cada módulo.
- Una empresa que elija firma en papel tendrá responsivas sin firma digital (`firma_ruta = ''`), igual que las que ARCO ya limpió.
