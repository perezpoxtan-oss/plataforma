# Revisión funcional módulo por módulo — 2026-10-06

Rama `feature/revision-funcional` (sale de `feature/seguridad-base`). Esta revisión no busca ataques. Responde dos preguntas: **¿algo no funciona?** y **¿nos saltamos algo de SEGCAT?**

## Resumen para el dueño del proyecto

- **Se revisaron todas las pantallas con todos los perfiles.** Una prueba automática entra como cada usuario demo (Administrador, Director, Recursos Humanos, Jefe de seguridad, Supervisor y dos Agentes), como Super Administrador (con y sin empresa elegida), como administrador de una empresa recién creada y como un usuario sin rol. En cada caso abre las 97 direcciones de consulta de la plataforma, unas 2,500 visitas en total. **Ninguna pantalla falla** (no hay errores 500), no aparecen avisos de PHP y ninguna página muestra texto de error. Una empresa nunca puede abrir los registros de otra.
- **Se abrieron las pantallas en un navegador real**, en computadora (1366 px) y en celular (390 px), como `admin.demo` y `agente.demo`. También se abrió cada ventana de captura y se escribió en sus campos. Se encontraron y corrigieron 3 fallas:
  1. **Bitácora de transporte (importante):** si el guardia presionaba `Esc` o hacía clic fuera de la ventana «Registrar Bitácora Logística», el formulario se borraba por completo. La ventana quedaba invisible y la página dejaba de responder hasta recargarla. **Corregido.**
  2. **Unir colaboradores duplicados:** al unir un alta provisional con el colaborador correcto, tres datos seguían apuntando al registro dado de baja: el anfitrión y «a quién visita» de Accesos, y el colaborador destino de un Pase de salida. **Corregido.** Una prueba nueva avisa si un módulo futuro olvida registrar su columna.
  3. **Hojas para imprimir en el celular:** en tres hojas con tablas anchas, la página entera se movía de lado. **Corregido.**
- **Comparación contra SEGCAT:** todos los módulos operativos están migrados. Lo que falta es poco y está identificado:
  - Los reportes que ya se sabía que estaban pendientes (Tablero de inicio, Informe ejecutivo, Tendencias y Bitácora general del día).
  - Unos detalles chicos: la agencia en «Salida a Tour», la Ficha de Hechos con los préstamos de llaves, la instalación como app (PWA), el responsable sugerido al dar de baja un equipo asignado y el conteo de colaboradores en la ficha de la sede.
  - Lo demás que se ve distinto es una **mejora documentada a propósito** (está en cada `docs/tecnico/*.md`).

## Hallazgos

| ID | Severidad | Qué se probó | Evidencia | Corrección | Estado |
|---|---|---|---|---|---|
| FUN-01 | Alta | Pantalla Bitácora de transporte: abrir «Registrar Bitácora Logística», cerrar con `Esc` o hacer clic fuera | PoC con Playwright: después de `Esc` el diálogo queda `open` y `hidden`, con 0 campos (antes 20) y la página inerte; un clic en la cabecera también lo vaciaba. Causa: el diálogo guardaba sus horarios sugeridos en `data-sugerencias`, el mismo atributo de las cajas de sugerencias de Accesos, y el manejador global de `Esc` / clic afuera lo trataba como una caja abierta (`hidden = true; textContent = ''`). Prueba: `RevisionFuncionalTest::test_fun01_…` | El atributo del diálogo se renombró a `data-horarios-sugeridos` en `seguridad/transporte/_alta.blade.php` y en `plataforma.js` (bloque Bitácora de transporte). `data-sugerencias` queda reservado a las cajas | Corregido |
| FUN-02 | Media | Unir un alta provisional con su colaborador correcto (Recursos Humanos) | `RevisionFuncionalTest::test_fun02_unir_un_duplicado_…`: sin la corrección, `accesos.host_colaborador_id`, `accesos.visita_colaborador_id` y `pases_salida.colaborador_destino_id` seguían con el id del provisional. Ya estaba anotado como pendiente en `accesos.md` y `pases-salida.md` | `AdministradorColaboradores::REFERENCIAS_ADICIONALES` (segunda columna de una tabla), que `fusionar()` también mueve. Guarda nueva: `test_fun02_toda_columna_que_apunta_a_colaboradores_…` revisa en el esquema cada llave foránea a `colaboradores` | Corregido |
| FUN-03 | Baja | Desborde horizontal a 390 px | Playwright: `/lost-found/auditoria` (sobra 204 px), `/novedades/{id}/imprimir` de Lost & Found (175 px) y `/novedades/{id}/acuse` (46 px). La causa es `.tabla-impresion` | En pantallas de hasta 575 px la tabla se desplaza dentro de la hoja (`display:block; overflow-x:auto`, solo `@media screen`; la impresión no cambia). Prueba `test_fun03_…` | Corregido |
| FUN-04 | Informativa | Recorrido HTTP de **todas** las rutas GET con los 7 roles demo, el Super Administrador (plantillas y Hotel Demo), un administrador de empresa nueva sin datos y un usuario sin rol. Parámetros con registros reales (primero y último de cada modelo, una firma de cada tipo, cada categoría de novedad, cada código QR del lector) más un id inexistente. También búsquedas con comodines (`%_'`), filtros, pestañas y paginación | `tests/Feature/SeguridadAuditoria/RecorridoPantallasTest.php` (3 pruebas, unas 2,500 peticiones). Cualquier aviso o *deprecated* que se registre en el log cuenta como falla | — | Revisado, sin hallazgo |
| FUN-05 | Informativa | Aislamiento de la empresa nueva y del usuario sin rol frente a ids reales de otra empresa | La misma prueba: ninguna ficha, firma, hoja ni QR de Hotel Demo responde 200; las redirecciones sin JavaScript (`/pases-salida/{id}` → `?pase=`) también terminan en 404 | — | Revisado, sin hallazgo |
| FUN-06 | Informativa | Errores de JavaScript (`console.error`, `pageerror`), peticiones fallidas y desborde en celular | Playwright sobre 173 pantallas (admin) y 125 (agente), a 1366 y 390 px. Se abrió una vez cada tipo de diálogo o botón de acción. Además una prueba «mono» que abre el alta de 33 pantallas, cambia cada lista, escribe en cada campo (dispara las búsquedas) y marca casillas. Después de FUN-01 y FUN-03: 0 hallazgos | — | Revisado, sin hallazgo |
| FUN-07 | Informativa | GET sin parámetros a endpoints JSON (`/accesos/buscar`, `/accesos/gafetes`, `/novedades/ficha-hechos`) desde el navegador | Responden 302 a la pantalla anterior (validación de Laravel). La interfaz siempre los llama con `Accept: application/json` y entonces responden 422 | No requiere | Revisado, sin hallazgo |
| FUN-08 | Baja | Documentación técnica desactualizada | `gafetes.md` decía que no había pantalla para subir el logo, y sí la hay. `turnos.md` y `usuarios.md` tenían «pendientes» que ya están resueltos | Textos corregidos | Corregido |
| FUN-09 | Baja | Dar de baja una llave que está prestada (EN USO) | Se permite. El préstamo sigue abierto y se recibe normal. Ya está anotado en `prestamo-llaves.md` como decisión pendiente | Decisión del dueño: ¿la baja debe exigir recibirla antes? | Pendiente de decisión |

Archivos compartidos que se tocaron (bloques marcados con «Seguridad: … FUN-0x»):
- `public/js/plataforma.js`: una línea en el bloque de Bitácora de transporte y su comentario.
- `public/css/plataforma.css`: 4 líneas después de `.tabla-impresion`.

## Comparación contra SEGCAT (inventario funcional §1–§6)

Significado de **falta / diferente-intencional / bug**:
- **falta:** SEGCAT lo tiene y la plataforma no.
- **diferente-intencional:** cambio documentado a propósito como mejora, en `docs/tecnico/<módulo>.md`, sección «Qué se corrigió respecto a SEGCAT».
- **bug:** no funciona como debería.

Esfuerzo: **S** = horas, **M** = 1 a 3 días, **L** = una semana o más.

### Transversal (§0–§3)

| Punto de SEGCAT | Estado | Esfuerzo | Nota |
|---|---|---|---|
| §2 Dashboard «Consola de Monitoreo Central» (8 KPI con gráfica Chart.js y detalle) | falta | M | Hoy el Inicio solo muestra pendientes (altas provisionales). Módulo `dashboard` sin ruta («en migración»). Pendiente conocido |
| §2 Enlaces del dashboard a «L&F urgentes» y «Pases vencidos» | falta | S | Los filtros de destino sí existen (`/lost-found?filtro=urgentes`, `/pases-salida?filtro=vencidos`); falta la tarjeta del dashboard |
| §3.2 Inactividad 15 min (header) y 20 min (proceso) | diferente-intencional | — | Un solo corte de 20 min con aviso 2 min antes (`acceso-y-diseno.md`) |
| §3.6 Logo de empresa | diferente-intencional | — | PNG/JPG/WEBP de hasta 512 KB, sin SVG (`empresas-y-sedes.md`) |
| §3.6 Firmas en base64 dentro de la base | diferente-intencional | — | Disco privado con permiso y alcance (`firmas.md`) |
| §3.7 Correo: `destinatarios_vouchers` global | diferente-intencional | — | Lista por empresa en Configuración → Avisos (`configuracion.md`) |
| §3.8 PWA (`manifest.json`, íconos, `theme-color`, `apple-mobile-web-app-capable`) | falta | S | No hay `manifest.json` ni íconos de app. El `sw.js` de SEGCAT no existía, así que no se pierde funcionalidad offline. Conviene decidirlo junto con las apps Android/iOS |
| §3.10 Alto contraste | diferente-intencional | — | Modos Normal / Sol / Noche; quien tenía alto contraste pasa a Sol |
| §3.10 Chart.js | falta | — | Solo lo usaban el dashboard y los reportes (ver arriba y §4.30) |
| §1.2 Segunda barra inferior del footer (tapada) | diferente-intencional | — | No se migró (en SEGCAT no se veía) |

### Módulos (§4)

| § | Módulo | Qué falta o cambia | Estado | Esfuerzo |
|---|---|---|---|---|
| 4.1 | Empresas | Completo: filtro Activas/Inactivas/Todas, alta solo para el Super Administrador y logo. El RFC se valida por formato | diferente-intencional (validación) | — |
| 4.2 | Sedes (Hoteles) | **Conteo de colaboradores** en la ficha de la sede | falta | S |
| 4.2 | Sedes | Código único por empresa (antes global); zona horaria propia | diferente-intencional | — |
| 4.3 | Zonas y áreas | Completo: 4 pantallas, lote, copiar pisos y secciones. Una sola tabla `espacios`; tope del lote 500 | diferente-intencional | — |
| 4.4 | Departamentos | Completo. Sedes en positivo («Todas» + lista); el aviso de nombre repetido es local, sin AJAX | diferente-intencional | — |
| 4.5 | Puestos | Completo | — | — |
| 4.6 | Turnos | Completo. Sedes en el alta y validación de horas | diferente-intencional | — |
| 4.7 | Colaboradores | Completo y ampliado: datos personales con permiso propio y altas provisionales | diferente-intencional | — |
| 4.7 | Colaboradores | Unir duplicados no movía todas las referencias | bug → corregido (FUN-02) | — |
| 4.8 | Usuarios | Completo, incluido el vínculo con el colaborador y el desbloqueo manual | — | — |
| 4.9 / 4.10 | Roles y Permisos | Completo. Roles tiene permisos propios (`roles.*`); alcance por módulo | diferente-intencional | — |
| 4.11 | Configuración — Correo | Completo (contraseña cifrada) | — | — |
| 4.11 | Configuración — **Costos de reposición** por sede (llave electrónica, metálica, gafete) | Se reemplazó por el «último costo cobrado» como sugerencia editable (`gafetes.md`, `equipos.md`). El primer voucher de un tipo no trae monto sugerido | diferente-intencional | (S si se quiere el costo fijo inicial) |
| 4.12 | Proveedores | Completo: ficha con Personal y Flotilla (enlazan al Padrón con `?nuevo=1&proveedor=`) | diferente-intencional | — |
| 4.13 | Padrón de personas | Completo (folio enmascarado para quien solo consulta) | diferente-intencional | — |
| 4.14 | Padrón vehicular | Completo: calcomanía con QR local y píldoras Propios / Flotillas / Taxis | diferente-intencional | — |
| 4.15 | Catálogo de llaves | Completo, más exportación CSV | — | — |
| 4.16 | Préstamo de llaves | Completo (CSV de auditoría, historial y «Registrar y capturar siguiente») | — | — |
| 4.16 | Préstamo de llaves | Baja de una llave EN USO | pendiente de decisión (FUN-09) | S |
| 4.17 | Gafetes | Completo: lote, impresión doble vista con logo y QR, baja con voucher | — | — |
| 4.18 | Vouchers | Completo, con filtros en el servidor y paginación | diferente-intencional | — |
| 4.19 | Equipos de seguridad | **Responsable sugerido al dar de baja un equipo ASIGNADO** (`equipo_responsable_activo_ajax.php`) | falta | S |
| 4.19 | Equipos de seguridad | Autocompletar marca/modelo y costo sugerido sin AJAX (`datalist`) | diferente-intencional | — |
| 4.20 | Responsivas | Completo. La devolución individual DAÑADO/EXTRAVIADO no se migró: en SEGCAT tampoco estaba enlazada | diferente-intencional | — |
| 4.21 | Estacionamientos | Completo, con ocupación real desde Accesos | — | — |
| 4.22 | Bitácora de accesos | **Salida a Tour: modo «Agencia» y agencia de tours** (SEGCAT creaba la agencia `AGENCIA_TOURS`). Hoy se capturan placas, marca y conductor, sin la agencia | falta | S |
| 4.22 | Bitácora de accesos | «Escanear Código QR» → lector universal; historial paginado con exportación | diferente-intencional | — |
| 4.22 | Bitácora de accesos | Host y «a quién visita» no se movían al unir duplicados | bug → corregido (FUN-02) | — |
| 4.23 | Bitácora de novedades | **Ficha de Hechos sin préstamos de llaves ni accesos** de la habitación o zona (SEGCAT incluía «accesos de llaves»). El gancho `FuenteFichaHechos` existe, pero ningún módulo lo implementa | falta | M |
| 4.23 | Bitácora de novedades | Categoría RECORRIDO_PC ya no se elige (tiene su propio módulo) | diferente-intencional | — |
| 4.23 | Bitácora de novedades | 7 formatos por categoría, 6 firmas del accidente, ticket de accidente automático por siniestro con lesionados, reapertura con motivo | — | — |
| 4.24 | Lost & Found | Completo: archivo, semáforo, cierre con firma, etiqueta, acuse, auditoría de inventario y días de resguardo por empresa | diferente-intencional | — |
| 4.25 | Robo — Seguimiento | Completo | — | — |
| 4.26 | Recorridos PC | Completo, más exportación CSV | diferente-intencional | — |
| 4.27 | Pases de salida | Completo: circuito de 5 grupos de firmas, rechazo, vencidos e impresión | — | — |
| 4.27 | Pases de salida | Colaborador destino no se movía al unir duplicados | bug → corregido (FUN-02) | — |
| 4.28 | Rutas de transporte | Completo. «Eliminar ruta» pasó a Suspender/Reactivar; hoja del día por fecha | diferente-intencional | — |
| 4.29 | Bitácora de transporte | Ventana de alta se vaciaba con `Esc` o con un clic afuera | bug → corregido (FUN-01) | — |
| 4.29 | Bitácora de transporte | Diálogos «Registrar Vehículo Nuevo» y «Registrar Chofer Nuevo» reemplazados por el alta automática en los padrones al guardar | diferente-intencional | — |
| 4.29 | Bitácora de transporte | Exportación `.xls` (HTML) → CSV | diferente-intencional | — |
| 4.30 | **Informe ejecutivo** y su versión para imprimir | falta (pendiente conocido) | L |
| 4.30 | **Tendencias** (periodos comparados, 10 secciones) | falta (pendiente conocido) | L |
| 4.30 | **Bitácora general del día** (Accesos, Novedades, Transporte, Préstamo de llaves; imprimir) | falta (pendiente conocido) | M |

### Endpoints AJAX (§5)

Todos tienen equivalente, salvo estos dos:

| Endpoint de SEGCAT | Estado | Nota |
|---|---|---|
| `equipos/equipo_responsable_activo_ajax.php` | falta | Ver §4.19 |
| `departamentos/…verificar_nombre_ajax.php`, `puestos/…`, `colaboradores/…verificar_num_empleado_ajax.php`, `llaves/…verificar_nomenclatura_ajax.php`, `equipos/…verificar_serie_ajax.php` y `…costo_sugerido_ajax.php` | diferente-intencional | El aviso en vivo usa la lista que ya trae la página. La regla real se valida en el servidor (antes aceptaban un `id_empresa` del cliente) |

Las búsquedas de caseta usan `/accesos/buscar`, `/accesos/en-sitio` y `/accesos/gafetes`. Los buscadores de transporte usan el lector universal y sugerencias locales. Las demás búsquedas usan `colaboradores|personas|proveedores|vehiculos/buscar` y `/lector/resolver`. El latido de sesión es `/sesion/latido`.

### Impresiones y exportaciones (§6)

Todas existen y el recorrido las abre sin errores, salvo las dos de Reportes:
- **Existen:** gafetes, etiquetas de llaves, CSV de préstamos, etiquetas de equipos, de equipos PC y de Lost & Found, calcomanía vehicular, hoja de responsiva, voucher triplicado, acuse, auditoría de Lost & Found, reporte de recorridos PC, vale de taxi, hoja del día, itinerario y exportación de transporte.
- **Faltan:** `informe_ejecutivo_pdf.php` y `bitacora_general_dia.php` (§4.30).
- **Se agregaron:** CSV de Accesos, Novedades, Llaves, Recorridos PC y Auditoría.

## Cómo repetir la revisión

```bash
php artisan test tests/Feature/SeguridadAuditoria   # recorrido HTTP + regresiones
RECORRIDO_DEBUG=1 php vendor/bin/phpunit tests/Feature/SeguridadAuditoria/RecorridoPantallasTest.php   # imprime rol, código y URL de cada visita
```

Para la parte de navegador:
1. Levanta el servidor local con los datos demo: `php artisan migrate:fresh --seed && php artisan plataforma:demo --password=…` y después `php artisan serve`.
2. Recorre con Playwright las URL que respondieron 200 en el recorrido. Recoge `console.error`, `pageerror`, respuestas de 400 o más y `scrollWidth > clientWidth` a 1366 y 390 px.
