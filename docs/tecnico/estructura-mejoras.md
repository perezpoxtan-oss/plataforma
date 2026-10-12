# Mejoras de Estructura y plataforma (ADR-0016)

Resumen técnico de lo que cambió en Roles, Matriz de permisos, Configuración, Inicio y Mis pendientes, campana, Auditoría, Usuarios, Empresas, Sedes, Zonas y edificios, Identidad y Manual. La decisión y sus razones están en [ADR-0016](../decisiones/ADR-0016-mejoras-de-estructura.md).

## Base de datos

Migración `2026_10_27_000100_mejoras_de_estructura`:

| Cambio | Para qué | `down()` |
|---|---|---|
| `users.preferencias_avisos` JSON nulo | `{"campana": [tipos], "correo": [claves]}` silenciados por la persona; nulo = todo | quita la columna |
| `auditoria.sede_id` + índice `auditoria_empresa_sede_idx` | sede del registro auditado | quita índice y columna |
| `modulos.nombre` de `espacios` → «Zonas y edificios» | nombre consistente | lo regresa a «Zonas y áreas» |
| `identidad` → sección «Plataforma», después de la bitácora | lo exclusivo del Super Administrador, aparte | lo regresa a «Sistema» entre Configuración y la bitácora |

`CatalogoSeeder` y `MenuSeeder` traen los mismos valores para instalaciones nuevas. La configuración nueva de la empresa vive en `empresas.preferencias`: `avisos_modulos` (interruptores por módulo), `auditoria_sede` (`propios` | `sede`) y el aviso `movimientos_sensibles` dentro de `avisos`.

## Piezas

| Pieza | Qué hace |
|---|---|
| `App\Services\Permisos\PerfilesDeRol` | `nivelPara()` (debajo / arriba de un rol), `copiar()` (permisos de otro rol o plantilla, filtrados por lo que el actor puede otorgar, conservando lo que no puede tocar), `perfiles()` (plantillas de la plataforma para el asistente), `menus()` / `mapaMenus()` (vista previa de menús con las reglas de `ConstructorMenu`). |
| `RolController` | `posicion_relacion` + `posicion_rol` → `nivel_jerarquia`; `copiar_de` en el alta (transacción: si no se pueden copiar, no se crea el rol). |
| `PermisoController` | `perfil()` (PUT `permisos/{rol}/perfil`, `perfil=perfil-ID | rol-ID | otro + perfil_rol`); `sinPantalla()` (módulos sin ruta: se excluyen del guardado y se conservan); `mixto` + `alcances[accion]` para no aplanar alcances distintos por acción. |
| `App\Support\GruposAvisos` | aviso → módulo; avisos no listados caen en «Otros avisos». `Empresa::aviso()` respeta el interruptor del módulo. |
| `ConfiguracionController` | vista por módulo con un solo `<form id="formAvisos">` (las casillas usan `form="formAvisos"`), `auditoria()` (PUT), `versionInstalada()` (archivo `VERSION`). |
| `MisPendientes` | renglones nuevos `altas_provisionales` y `procedimientos_aprobar`; `vista` = sin ceros y con `candidatos` agrupado (`detalle`); «Altas por verificar» con su padrón o desglose. `PanelController` arma Inicio desde `vista` (instancia propia, cálculo fresco). |
| `App\Services\Notificaciones\PreferenciasAvisos` | opciones por persona, `quienesLoReciben()` (campana) y `correosQueLoReciben()` (correo). `cuenta_contrasena` no se silencia. |
| `App\Services\Auditoria\MovimientosSensibles` | `sedeDe()` (llena `auditoria.sede_id` en `Auditoria::creating`), `avisar()` (en `Auditoria::created`; nunca impide guardar). |
| `LectorAuditoria::alias()` / `claseDeAlias()` / `nombreDe()` | «Ver historial»: `auditoria?registro=rol&id=…`; solo tipos conocidos. Componente `componentes.ver-historial`. |
| `AdministradorUsuarios` | `sedes_extra[]` → filas extra de `usuario_roles` (mismo rol); `sinVinculoQuePidePases()` para «Cuentas por revisar». |
| `EmpresaController::modulos()` | PUT `empresas/{empresa}/modulos`, solo Super Administrador; Dirección siempre activa; submódulo solo si su padre lo está. |
| `Manual` | `textoPorApartado()`, `palabras()` (filtro en vivo), `buscar()` (resultado con apartado y fragmento), `enlaceApartado()` (componente `componentes.ayuda-apartado`). |

Componentes de vista nuevos: `componentes/ver-historial`, `componentes/ayuda-apartado`, `componentes/empresa-de-trabajo`, `administracion/roles/_posicion`, `administracion/permisos/_asistente`, `administracion/configuracion/_auditoria`. El parcial `administracion/partes/avisos` acepta `session('siguientes')` = `[[texto, url, icono], …]` (criterio 5). JS y CSS en los bloques «Mejoras estructura» de `plataforma.js` / `plataforma.css`, más dos cambios puntuales: el renglón con desglose de Mis pendientes y el filtro de usuarios por varias sedes.

## Reglas de seguridad que se conservan

- Copiar o aplicar perfiles pasa por `AdministradorRoles::sincronizarPermisos` (nadie otorga lo que no tiene ni con más alcance).
- Rutas nuevas: 404 si el registro es de otra empresa y 403 sin permiso (MatrizDeRutasTest las recorre solas); `mi-cuenta.avisos` es libre (solo la propia cuenta).
- Varias sedes: AZ-01 se aplica a cada sede extra; las sedes fuera del alcance de quien edita se conservan.
- El filtro «Su sede» de la bitácora solo se activa si la empresa lo elige; «Solo los propios» siempre ve lo suyo.

## Lo que no se hizo (y por qué)

- **Alcance distinto por acción en la pantalla**: el modelo y el `Autorizador` lo permiten, pero varias pantallas autorizan `x.editar` sin el registro y lo buscan con la consulta limitada por `x.ver`; con «Ver: empresa / Editar: sede» se podría editar fuera de la sede. Para habilitarlo hay que revisar cada controlador (pasar el registro a `Gate::authorize` o limitar la búsqueda por el permiso de la acción).
- **Avisos en vivo** (sin esperar 30 s): el hospedaje compartido no permite procesos permanentes (websockets).
- **Renombrar «Asistente»**: las migraciones de otros módulos lo buscan por nombre.

## Pruebas

`tests/Feature/Seguridad/MejorasEstructuraTest.php` (20 pruebas) y ajustes en MenuTest, MenusReorganizadosTest, AyudasDeCapturaTest, EmpresasYSedesTest, RolesYPermisosTest y MatrizDeRutasTest.
