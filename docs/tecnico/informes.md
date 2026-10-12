# Informes: Tablero, Bitácora general del día, Tendencias e Informe ejecutivo

Decisión: [ADR-0017](../decisiones/ADR-0017-informes.md) · Manual: [tablero](../usuario/tablero.md), [bitácora del día](../usuario/bitacora-del-dia.md), [tendencias](../usuario/tendencias.md), [informe ejecutivo](../usuario/informe-ejecutivo.md) · Curso: lección 46.

Los cuatro módulos del área Seguridad que existían en el catálogo sin pantalla (`dashboard`, `bitacora_dia`, `tendencias`, `informe_ejecutivo`) ya tienen pantalla. Son de **solo lectura**: no crean ni cambian registros, no generan avisos ni eventos de auditoría.

## Rutas y permisos

| Ruta | Nombre | Permiso |
|---|---|---|
| `GET /informes/tablero` | `tablero.index` | `dashboard.ver` |
| `GET /informes/tablero/detalle?ficha=&sede=&parcial=1` | `tablero.detalle` | `dashboard.ver` + `ver` del módulo de la ficha (404 si no) |
| `GET /informes/tablero/imprimir?sede=` | `tablero.imprimir` | `dashboard.ver` + `dashboard.imprimir` |
| `GET /informes/bitacora-del-dia?fecha=&sede=&tipo=` | `bitacora_dia.index` | `bitacora_dia.ver` |
| `GET /informes/bitacora-del-dia/imprimir` | `bitacora_dia.imprimir` | `bitacora_dia.imprimir` |
| `GET /informes/bitacora-del-dia/exportar` | `bitacora_dia.exportar` | `bitacora_dia.exportar` |
| `GET /informes/tendencias?periodo=&desde=&hasta=&sedes[]=&seccion=&fresco=1` | `tendencias.index` | `tendencias.ver` |
| `GET /informes/tendencias/exportar?seccion=` | `tendencias.exportar` | `tendencias.exportar` (403 si no ve el módulo de la sección) |
| `GET /informes/tendencias/imprimir?periodo=&desde=&hasta=&sedes[]=&seccion=` | `tendencias.imprimir` | `tendencias.ver` + `tendencias.imprimir` |
| `GET /informes/ejecutivo?sedes[]=&fresco=1` | `informe_ejecutivo.index` | `informe_ejecutivo.ver` |
| `GET /informes/ejecutivo/imprimir` | `informe_ejecutivo.imprimir` | `informe_ejecutivo.ver` + `informe_ejecutivo.imprimir` |
| `GET /informes/ejecutivo/exportar` | `informe_ejecutivo.exportar` | `informe_ejecutivo.exportar` |

Los nombres de ruta usan el prefijo de cada módulo (`tablero.`, `bitacora_dia.`…) porque `ConstructorMenu` marca activo el renglón por prefijo. `CatalogoSeeder::RUTAS` y la migración `2026_10_28_000100_informes` ponen `modulos.ruta`; con eso el menú **Informes** aparece y `PermisoController::sinPantalla()` deja de marcarlos «Próximamente» (sin código especial). Por plantilla ven los informes Administrador y Director (empresa), Jefe de seguridad, Asistente y Supervisor (sede); el Agente no (no son de Operación ni Padrones). No se cambió `RolesPlantillaSeeder`.

### Permiso «Imprimir» (decisión del dueño, mapa de procesos)

Los cuatro informes tienen la acción `imprimir` en el catálogo (`CatalogoSeeder`; la Bitácora del día ya la tenía). El botón **Imprimir** de cada pantalla sale solo con `<modulo>.imprimir` (`puedeImprimir` en la vista) y cada hoja exige `ver` **e** `imprimir` (la de la Bitácora del día, solo `imprimir`, como antes). Las plantillas lo reciben por su regla general (todas las acciones de Seguridad para Jefe/Supervisor/Administrador; `imprimir` está en las listas de Director y Asistente).

Migración `2026_10_29_000520_permiso_imprimir_informes`: da de alta `imprimir` en `dashboard`, `informe_ejecutivo` y `tendencias` y lo otorga a **todo rol** (plantillas y roles por empresa) que tenga `ver` en ese módulo, **con el mismo alcance** de su `ver` (nadie pierde la impresión que tenía). `bitacora_dia` no se toca: su hoja ya pedía `imprimir` y otorgarlo a todos los que la ven ampliaría permisos quitados a propósito. `down()` borra la relación módulo-acción de los tres módulos; los `rol_permisos` se van en cascada.

Hojas nuevas: `informes.tablero-imprimir` (fichas sin listados ni diálogos, `_tablero-hoja`) y `informes.tendencias-imprimir` (mismas secciones y gráficas SVG que la pantalla, sin «Exportar»; la parte común está en `_tendencias-secciones`). Ambas usan `informes._hoja`.

## Alcance (`App\Services\Informes\AlcanceInformes`)

Cada dato respeta **dos** permisos: el del informe y el `ver` del módulo de origen. Las sedes de un dato = intersección de `Autorizador::sedesPermitidas()` de ambos y de las sedes elegidas en el filtro (las que no son de su alcance se ignoran). Con «Solo los propios» en el módulo de origen se agrega `creado_por = actor`. Todo corre dentro de `Tenant::conEmpresa()` (scope de empresa). Casos especiales: Colaboradores usa `scopeEnSedes` (sede física o adicional); Usuarios usa `AdministradorUsuarios::limitarAlcance` y `usuario_roles.sede_id`; Vacantes usa `AdministradorVacantes::limitar` y `aplicanEn`; Vehículos y Proveedores no son por sede (se cuentan en toda la empresa, como sus padrones). Superadministrador sin empresa: pantalla «elige la empresa de trabajo»; las hojas y exportaciones responden 404.

## Consultas (`Indicadores`)

- Conteos y desgloses con `COUNT`/`SUM` y `GROUP BY` en la base (`Indicadores::por`, `::conteos`).
- Series por tiempo en **una consulta** con `SUM(CASE WHEN fecha >= ? AND fecha < ? …)` por cubeta (`Indicadores::serie`); las cubetas se calculan en PHP en la hora local (`Periodo::cubetas`) y se pasan en UTC: sin `DATE()`, `HOUR()`, `strftime` ni `CONVERT_TZ`, igual en MariaDB y SQLite.
- Hora del día, duración de visitas, tiempo de cierre y de respuesta: se recorren **solo dos o tres columnas** con `cursor()` (sin modelos), con tope de 20 000 filas.
- Listados con límite: 200 (Tablero), 100 (Informe ejecutivo), 500 por tipo (Bitácora del día), con eager loading.
- Índices nuevos (migración `2026_10_28_000100_informes`): `accesos(empresa_id, entrada_at)`, `novedades(empresa_id, created_at)`, `movimientos_transporte(empresa_id, created_at)`, `pases_salida(empresa_id, created_at)`.

## Mejora o empeora (Tendencias)

`Tendencias::SENTIDOS` dice, por sección y título de cifra, si subir es bueno (`Indicadores::SUBIR_ES_BUENO`), malo (`SUBIR_ES_MALO`) o neutro (`NEUTRO`, volumen de operación). `Tendencias::seccion()` agrega el sentido como quinto elemento de cada cifra **después** de leer la caché (cambiar el mapa no espera a que venza). `Indicadores::valorar(actual, anterior, sentido)` devuelve la comparación más `estado` (`mejora` | `empeora` | `neutro` | `igual`) y `texto` («mejoró 12 %», «empeoró 8 %», «subió 5 %», «Sin cambio»; sin porcentaje si el anterior es 0). La vista pinta `.cambio-informe.mejora` (verde) y `.empeora` (rojo), con variantes para modo Noche; la flecha ▲/▼ sigue marcando la dirección y la frase dice si es bueno o malo (no depende solo del color). El CSV de la sección agrega la columna **Lectura** con el mismo texto. Toda cifra comparable nueva debe declararse en `SENTIDOS` (`DecisionesInformesYAuditoriaTest` lo revisa).

| Sube = malo | Sube = bueno | Neutro |
|---|---|---|
| Tickets abiertos, tiempo promedio de cierre, casos de robo, valor estimado, reportes de pérdida, accidentes, monto pagado en taxis | Regresaron a la propiedad, candidatos registrados, contratados | Ingresos registrados, objetos encontrados, préstamos de llaves, pases creados, movimientos, pasajeros, altas de colaboradores y de usuarios |

## Periodo (`Periodo`)

`semana` (lunes a hoy), `mes`, `trimestre`, `anio`, `personalizado` (`desde`/`hasta`, máximo 731 días; fechas inválidas → últimos 30 días). El periodo anterior dura lo mismo y termina el día antes. Agrupación: ≤ 14 días por día, ≤ 120 por semana, si no por mes. Las cubetas del anterior son las mismas recorridas `dias()` hacia atrás (comparación punto a punto). Zona: la de la sede si se eligió una sola, si no `HoraLocal::zona()`.

## Tablero (`Tablero`)

Fichas (clave → permiso de origen): `colaboradores` (colaboradores.ver), `en_sitio` (accesos.ver: `estado = en_sitio`, `movimiento = entrada`), `llaves` (prestamo_llaves.ver: `vigentes()` / llaves activas), `transporte` (transporte.ver: 7 días locales, sin anulados), `novedades` (novedades.ver: estatus ≠ resuelto), `lost_found` (lost_found.ver: `ArchivoLostFound::soloUrgentes`, ≥ 70 % del umbral; vencidos ≥ 100 %; enlace `?filtro=urgentes`), `pases` (pases_salida.ver: `AdministradorPasesSalida::filtrar('vencidos')`, enlace `?filtro=vencidos`), `equipos` (equipos.ver: sin bajas). Cada ficha se calcula en `try/catch`: si falla se reporta al log y se omite. El detalle se pide con `fetch` (bloque «Mejoras informes» de `plataforma.js`) y se pinta en un `<dialog>`; sin JavaScript el enlace abre la página del detalle.

**Dónde vive:** pantalla propia enlazada desde Inicio («Ver el tablero…», solo con `dashboard.ver`). Inicio sigue siendo «lo que espera tu respuesta» (ADR-0016) y no paga el costo de 8 consultas más en cada entrada.

## Informe ejecutivo (`InformeEjecutivo`)

Bloques: Personal y activos (colaboradores, usuarios por rol, vehículos por tipo, proveedores por categoría — sin RFC ni teléfono), Seguridad física (llaves, equipos), Robo 90 días (casos, sin parte, valor estimado), Pases 90 días (creados por motivo, fuera ahora, vencidos), Transporte 30 días (movimientos, puntualidad, gasto en taxis, rutas activas), Candidatos y vacantes (en proceso por etapa, contratados 90 días, vacantes publicadas con candidatos en proceso y días publicada, tiempo para cubrir = promedio publicada→cerrada «cubierta» en 180 días; **sin nombres**), Autorizaciones 30 días (por estado, tiempo de respuesta, escaladas, esperando). Resultado en caché 5 minutos.

## Bitácora general del día (`BitacoraDelDia`)

Día local de la sede elegida (o de la única sede de la persona; si no, `HoraLocal`). Fechas futuras o imposibles → hoy. Tipos: `accesos` (entradas por `entrada_at` con su movimiento; salidas por `salida_at` de las entradas), `novedades` (abiertos por `created_at`, notas `tipo = nota`, cierres por `cerrado_en`), `transporte` (sin anulados), `llaves` (préstamos y devoluciones, sin anulados), `pases` (`salio_en`, `regreso_en`), `recorridos` (inicio y `finalizado_en`). Orden por hora. La hoja imprimible agrega líneas de firma «Entrega / Recibe el turno».

## Gráficas (`App\Support\GraficaSvg`)

La CSP solo permite scripts propios y no había Chart.js en `public/vendor` ni acceso al registro de npm desde el entorno de trabajo, así que las gráficas son **SVG generado en el servidor** (barras horizontales, columnas y líneas actual contra anterior). Sin JavaScript. Cada barra/punto lleva `<title>` (texto emergente) y cada gráfica su tabla en «Ver los datos de la gráfica» (accesibilidad). Más de 10 categorías se agrupan en «Otros». Colores por variables CSS (`--color-primario`, modo Noche incluido).

## Caché

`Cache::remember` 5 minutos para Tendencias (por sección) e Informe ejecutivo. La clave incluye empresa, una **huella del alcance** (`AlcanceInformes::huella`: sedes efectivas por permiso de origen y «propios»), el periodo/zona y el día. «Actualizar cifras» (`?fresco=1`) la borra. Sin invalidación por eventos: el dato más viejo que se puede ver tiene 5 minutos. El Tablero y la Bitácora del día no usan caché (son del momento).

## Datos de ejemplo

`plataforma:demo` (paso `informesDemo`) agrega, solo la primera vez, 87 días anteriores: visitas terminadas, llegadas y salidas de transporte (a tiempo o con retraso; nunca vales pendientes), tickets de Reporte General cerrados y préstamos devueltos.

## Pruebas

`tests/Feature/Seguridad/InformesTest.php` (IF-01…IF-14), `tests/Feature/Seguridad/DecisionesInformesYAuditoriaTest.php` (permiso Imprimir, su migración, mejora/empeora): menú y Matriz, alcance por empresa y sede, fichas sin permiso y fichas que fallan, número de consultas, comparación con el periodo anterior, filtro de sedes y caché, CSV, impresión, datos sensibles, bitácora por fecha y zona horaria, superadministrador sin empresa. `RecorridoPantallasTest` recorre todas las fichas, periodos, secciones y fechas inválidas.
