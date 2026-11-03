# ADR-0025 — Conectores de equipos de acceso ZKTeco y Hikvision

**Fecha:** 2026-11-03 · **Estado:** aceptada · **Detalle técnico:** [equipos-acceso.md](../tecnico/equipos-acceso.md)

## Contexto

Decisión 10 del dueño (3 nov): conectar los equipos de acceso ZKTeco y Hikvision para que la entrada y salida del personal se registre sola. Restricciones: hospedaje compartido (sin SSH, sin procesos escuchando, sin `exec`, sin cron nuevo); la esencia de la caseta no cambia; reconocimiento facial NO; antipassback solo avisa; lo nuevo va en Configuración. La propuesta de credencialización (12 oct) los dejó como fases 3 y 4.

## Decisión

1. **La plataforma solo recibe.** ZKTeco por su protocolo PUSH (el equipo pregunta cada 30 s y recoge su cola de comandos); Hikvision empujando sus eventos ISAPI a una dirección con token. Nada sale de la plataforma hacia la red del cliente.
2. **Una interfaz `Conector` y una clase por marca.** El registro de marcajes, la cola, la pantalla y el aviso de sin conexión son comunes: otra marca es una clase y una línea.
3. **Mismo servicio que la caseta.** Los marcajes crean entradas y salidas con `RegistroAccesos::entradaDeColaborador` y `MovimientosAccesos::salida` (actor nulo + equipo), extraídos con el menor cambio: la Bitácora, Gente en sitio y Asistencia los ven sin cambiar.
4. **Identidad del colaborador** = número de empleado (PIN) o su etiqueta NFC/RFID; vigente = activo y, si usa credenciales, con una DISPONIBLE o ASIGNADA sin vencer. Lo no vigente se rechaza y avisa a la caseta.
5. **Altas y bajas por eventos de los modelos** (Credencial y Colaborador) hacia la cola de cada ZKTeco; sin nombre de la persona.
6. **Antipassback solo avisa:** la entrada de quien sigue en sitio se registra y la anterior se cierra sin inventar hora de salida.
7. **Hikvision sin sincronización** hasta el puente local (fase siguiente, documentada, no construida).
8. **Permisos sin módulo nuevo:** la pantalla usa `configuracion.ver` / `configuracion.editar` por sede (y `accesos.aprobar` para consultar el estado).

## Alternativas descartadas

- **Que la plataforma llame al equipo (SDK, ISAPI directo):** imposible desde el hospedaje (el equipo está en la red privada del cliente) y obligaría a abrir puertos en su red.
- **Un proceso que escuche (TCP/UDP del SDK de ZKTeco):** no hay procesos permanentes en el hospedaje.
- **Un módulo nuevo «Equipos de acceso» en el catálogo comercial:** agrega plan, menú y dependencias; queda como decisión del dueño (hoy va con Accesos y Configuración).
- **Bloquear por antipassback:** el dueño decidió solo avisar.
- **Guardar el número de tarjeta completo y el nombre que manda el equipo:** datos personales fuera de lo necesario; se guarda enmascarado y el nombre no se guarda.

## Consecuencias

- La caseta no gana pasos; en la Bitácora el registro dice «(por Equipo: <nombre>)».
- ZKTeco se identifica solo con su número de serie: se recomienda fijar la IP pública del cliente por equipo.
- Hikvision necesita que alguien cargue las tarjetas en el equipo mientras no exista el puente.
- Los formatos se probaron con fixtures del protocolo público; falta validar cada firmware con un equipo real (plan de QA PI-EQ).
