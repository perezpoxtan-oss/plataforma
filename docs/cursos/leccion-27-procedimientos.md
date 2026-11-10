# Lección 27 — Procedimientos

**Para:** Agentes, Supervisores, Jefes de seguridad, Directores y Administradores · **Duración:** 25 minutos

## Idea clave

Los procedimientos dicen **qué hacer** en cada situación. Se escriben como **borrador**, otra persona los **aprueba con su firma** y quedan **publicados**. Cada persona a la que aplican los **lee y firma «Leí y entendí»**. Si se cambian, se hace una **versión nueva** y hay que firmar otra vez.

## Práctica (en QA, con los datos demo)

1. **`agente.demo`** (celular): en **Inicio** ves «Tienes 3 procedimientos por leer y firmar». Toca **Leer ahora** → `PRO-SEG-001 Robo en habitación` (versión 2: ya habías firmado la 1, pero cambió). Prueba **A+** y **A−**, lee los pasos (los críticos en rojo), marca la casilla, firma y toca **Firmar de enterado**.
2. **`agente.demo`**: en **Procedimientos** toca la categoría **Emergencias** y busca «incendio». Abre `PRO-PC-002` con **Leer** y fírmalo con **Usar mi firma guardada** si la guardaste en el paso 1.
3. **`admin.demo`**: en **Inicio** ves «1 procedimiento espera tu aprobación». Abre `PRO-SEG-003 Entrega de turno` (lo escribió el Supervisor). Toca **Pedir cambios** sin escribir nada: el sistema no te deja. Escribe «Agrega revisar la bitácora de llaves» y confirma: regresa a borrador.
4. **`supervisor.demo`**: abre `PRO-SEG-003`, toca **Editar borrador**, agrega un paso con **Agregar paso**, súbelo con la flecha y toca **Guardar y enviar a revisión**. Intenta aprobarlo tú: no aparece el botón (no se aprueba lo propio).
5. **`admin.demo`**: aprueba `PRO-SEG-003` con tu firma. Luego abre la pestaña **Acuses**: verás quién falta.
6. **`jefe.demo`**: abre `PRO-PC-005 Huracán` (la versión 2 está en borrador; la 1 rige). Mira los **adjuntos** (plano y directorio). Toca **Editar borrador**, cambia un paso, escribe **qué cambió** y envíalo a revisión. Pídele a **`director.demo`** que lo apruebe.
7. **`admin.demo`**: abre `PRO-SEG-001` → pestaña **Versiones**: quién escribió, envió y aprobó cada versión. Toca **Imprimir** y escanea el **QR** de la hoja con tu celular.
8. **`jefe.demo`**: abre `PRO-ACC-004 Pérdida de llave maestra` (borrador) y envíalo a revisión; observa que no puedes aprobarlo tú.

## Para recordar

- **Publicado** = rige. Una versión publicada **no se edita**: se hace **Nueva versión** y la anterior sigue vigente hasta que se aprueba la nueva.
- Nadie aprueba lo que **él mismo** escribió o envió. **Pedir cambios** exige explicar qué corregir.
- Debes firmar los procedimientos que aplican a tu **sede**, **departamento** o **puesto**. Si sale una versión nueva, firmas otra vez.
- El **QR** de la hoja impresa abre el procedimiento en el celular.
- **Retirar** deja un procedimiento como obsoleto sin borrar su historial.
