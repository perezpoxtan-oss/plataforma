# ADR-0019 — Quién autoriza: cobertura real, la cuenta sigue al colaborador, delegar y altas por verificar

**Fecha:** 2026-10-27 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/quien-autoriza.md](../tecnico/quien-autoriza.md)

## Contexto

La revisión de vínculos (grupo A, hallazgos A-03, A-04, A-06, A-10 y A-11; grupo B, B-11) encontró que la tarjeta de Departamentos y la pantalla Responsables contaban cualquier fila de responsable, mientras la caseta solo contaba a los activos de esa sede: una visita a un departamento «con responsable» entraba sin autorización si su responsable estaba dado de baja, sin permiso o era de otra sede, y nadie lo veía. Además se podía nombrar responsable «en todas sus sedes» a quien solo entra a una (y luego recibía un 404), la cuenta y su colaborador no se sincronizaban, «No molestar» estaba escondido en Autorizaciones y las altas de colaboradores seguían otro circuito que las de los padrones. Decisiones del dueño DD-11 y DD-22.

## Decisión

1. **Una sola regla de cobertura** (`App\Services\Autorizaciones\Cobertura`): cuenta activa + permiso `autorizaciones.responder` con alcance en la sede + fila de esa sede (o de todas). `Autorizaciones::responsablesDe()` la usa, así que avisos, bandeja, escalamiento, Reservas y Entrevistas la heredan. Las pantallas la consultan para avisar «Sin quien autorice en…».
2. **Sin quien autorice, espera al supervisor (DD-11):** el acceso nace pendiente con `autorizacion = sin_resp` (el mismo estado que ya usaban proveedor y contratista) y se avisa al supervisor y al jefe de seguridad de la sede. Es configurable (`preferencias.recepcion.sin_cobertura`: `supervisor` de fábrica o `directo`). Sin migración.
3. **La pérdida de cobertura avisa:** después de dar de baja una cuenta (directa o en cascada), cambiar sus roles o sedes, o cambiar o dar de baja un rol, se revisa la cobertura (`Cobertura::revisarDespues`); un aviso por departamento a quien nombra responsables, que pierde su botón al recuperarse.
4. **A-04:** al nombrar responsables solo se aceptan las sedes a las que entra la cuenta; las filas viejas se marcan en pantalla y solo cubren donde la cuenta entra; un aviso viejo de otra sede muestra una explicación en vez de 404.
5. **Manda el colaborador (DD-22):** nombre y número de la cuenta se copian del colaborador al editar, verificar, unir o importar; migración de una sola vez sin `down()` efectivo (eran copias).
6. **«Delegar mis aprobaciones» en Mi cuenta:** la misma tabla `delegaciones`; se delega en quien apruebe algo; cada módulo pasa al delegado solo lo que él puede aprobar (si no, el titular lo sigue recibiendo). Mis pendientes de quien delega no cuenta lo que su delegado puede resolver.
7. **Una sola bandeja «Altas por verificar»** con pestañas y los mismos tres caminos; «Rechazar» de colaboradores = baja con motivo auditada (sin migración).

## Consecuencias

- Las empresas que tenían visitas a departamentos sin quien autorice verán esas visitas **pendientes** (antes entraban directo). Es el cambio pedido por DD-11; se puede volver a «La visita pasa sin esperar».
- Una consulta fija más por petición por cada responsable (su permiso), recordada en la petición; las pruebas de número de consultas lo anotan.
- Verbos nuevos en botones: ninguno fuera de los diez; «Aprobar · es el mismo» viene de DD-20. Etiquetas «Es correcto» / «Es el mismo» son texto de apoyo de los caminos.
- El reloj y el suplente de «Altas por verificar» y de la espera del supervisor llegan con C-22 (Etapa 4); hoy se ve la antigüedad y el color de la espera.
