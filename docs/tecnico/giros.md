# Adaptación por giro (etapa 1: adaptar lo que ya existe)

Cada empresa tiene un **giro** (`empresas.rubro_id` → `rubros.clave`): `hotel`, `corporativo` («Corporativo / industria»), `condominio` o `fraccionamiento`, con su terminología en `rubros.terminologia` (`database/seeders/RubrosSeeder.php`). Antes solo la usaban los espacios, los menús y las sedes; la Bitácora de accesos y los textos de la plataforma eran de hotel para todos. Esta etapa adapta lo que ya existe, **sin tablas nuevas, sin migraciones y sin cambiar datos**.

**Garantía de hotel:** para una empresa de giro `hotel` (o sin giro, o con una clave desconocida) todo queda exactamente igual: mismos tipos, mismos textos, mismo manual, mismo HTML (la sustitución ni siquiera se ejecuta). Las pruebas existentes no cambiaron.

## Un solo ayudante: `App\Support\Terminos`

| Método | Para qué |
|---|---|
| `Terminos::para($empresa)`, `deEmpresaId($id)`, `actual()` (empresa activa del `Tenant`), `deRubro($clave, $terminologia)` | Obtener los términos. `deEmpresaId` se recuerda por petición en `RegistroTerminos` (scoped; se olvida al guardar una `Empresa` o un `Rubro`). |
| `esHotel()`, `esCorporativo()`, `esResidencial()` | Giro (condominio y fraccionamiento son residenciales). |
| `visitante()`, `colaborador()`, `espacio()` y sus plurales, `abreviaturaEspacio($tipo)` | Huésped / Visitante / Visita; Colaborador / Residente; Habitación / Oficina / Departamento / Casa; «Hab.», «Depto.», «Casa». |
| `tiposAcceso()`, `opcionesTipo()`, `ofreceTipo($tipo)`, `etiquetaTipo($tipo)`, `etiquetasTipo()` | Tipos de acceso que se ofrecen y su nombre (ver tabla). `etiquetaTipo` de un tipo que el giro ya no ofrece devuelve su nombre de siempre. |
| `motivos()`, `motivoPorDefecto()`, `etiquetaMotivo($m)`, `visitaLlevaEspacio()` | Motivos de la visita y si la visita dice a qué departamento o casa va. |
| `texto($s)`, `html($html)` | Sustitución de las palabras de hotel que quedaron escritas (ver abajo). |

Nadie más pregunta por el giro: las vistas y servicios llaman a `Terminos`.

## Tipos de acceso por giro

Las claves internas (`colaborador`, `huesped`, `visitante`, `proveedor`, `contratista`, `emergencia`) **no cambian**, ni el CHECK `accesos.tipo`, ni los datos. Solo cambia qué tipos se ofrecen y cómo se llaman.

| Clave | Hotel | Corporativo | Condominio / fraccionamiento |
|---|---|---|---|
| `colaborador` | Colaborador | Colaborador | Residente |
| `huesped` | Huésped (habitación, reserva, pase, agencia, conductor, tour) | — no se ofrece | — no se ofrece |
| `visitante` | Personal Externo / Visita | Visitante | Visita (motivo «Visita a Residente» o «Visita a Administración»; «¿A qué departamento/casa va?») |
| `proveedor` | Proveedor | Proveedor | Servicio / Proveedor (tarjeta: «Servicio») |
| `contratista` | Contratista (Obra) | Contratista (Obra) | Contratista (Obra) |
| `emergencia` | Servicio de Emergencia | Servicio de Emergencia | Servicio de Emergencia |

- **Formulario** (`seguridad/accesos/_ingreso.blade.php`): tipos de `opcionesTipo()`; los bloques de huésped (Más detalles con habitación/reserva/pase/agencia y el conductor) solo se dibujan si el giro ofrece `huesped`. En residenciales los motivos son `colaborador` («Visita a Residente», el predeterminado) y `departamento` («Visita a Administración»; el selector dice «¿A qué área de la administración va?»); no hay Recursos Humanos. La visita lleva **«¿A qué departamento va?»** (o casa) con sugerencias (`datalist`) de los espacios de nivel «área específica» de las sedes donde registra; se guarda en `accesos.habitacion` (la misma columna que la habitación del huésped, 20 caracteres).
- **Servidor** (`RegistroAccesos::registrar`): después de validar la sede, el tipo debe estar en `ofreceTipo()` de la empresa de la sede («Ese tipo de persona no se registra en esta empresa. Elige uno de la lista.») y el motivo en `motivos()` («Elige el motivo de la visita de la lista.»). Un corporativo no registra un Huésped aunque mande la clave. `habitacion` solo se guarda para `huesped` o para `visitante` en residenciales.
- **Pantalla:** filtro de tipo con `tiposAcceso()`; tarjeta con `Acceso::etiquetaTipo()` (ahora según el giro de la empresa del registro) y `etiquetaMotivo()`; pastilla `abreviaturaEspacio()` («Depto. 101»); diálogos «Salida a Tour / Regreso de Tour» solo en hotel («Salida Temporal / Regreso» en los demás). En «Dar salida» la tarjeta usa `etiqueta_espacio` y `nombre_espacio` del resumen JSON.
- **Registros de antes:** un tipo que el giro ya no ofrece (p. ej. un `huesped` en una empresa que cambió a corporativo) se muestra con su nombre; la pastilla va entre las marcas que apagan la sustitución.
- **Informes:** Tablero, Bitácora del día y Tendencias usan `Terminos::actual()->etiquetasTipo()`; la exportación CSV usa `etiquetaTipo()` y los encabezados pasan por `texto()` («Habitación» → «Oficina», «Departamento»…). Recepción y autorizaciones usan `etiquetaTipo()` y `etiquetaMotivo()`.

## Palabras en toda la plataforma: `Terminos::html()`

El middleware `App\Http\Middleware\AplicarTerminos` (grupo `web`, después de `EstablecerEmpresa`) pasa por `Terminos::html()` toda respuesta `text/html` con empresa activa que **no** sea hotel. Así pantallas, menús, avisos de éxito o error, impresiones y etiquetas dicen las palabras del giro sin condiciones regadas en las vistas. Los correos pasan por lo mismo en `MessageSending` (`AppServiceProvider`), con la empresa activa al enviarse (en tareas programadas sin empresa activa el correo sale como antes).

Qué cambia (en este orden; cuida mayúsculas, plural y el artículo: «la habitación» → «el departamento», «el huésped» → «la visita», «de la habitación» → «del departamento»):

| Texto de hotel | Corporativo | Condominio | Fraccionamiento |
|---|---|---|---|
| Lost & Found | Objetos olvidados | Objetos olvidados | Objetos olvidados |
| Devuelto al Huésped / Nombre del huésped / Huésped o persona externa | Devuelto a su dueño / Nombre de quien lo perdió / Persona externa | igual | igual |
| Huésped(es) | Visitante(s) | Visita(s) | Visita(s) |
| Personal Externo | Visitante | Visita | Visita |
| Habitación(es) | Oficina(s) | Departamento(s) | Casa(s) |
| Colaborador(es) | (sin cambio) | Residente(s) | Residente(s) |

Qué **no** toca: `<script>`, `<style>`, `<textarea>`, comentarios, valores de campos y atributos que no se leen (solo cambia `placeholder`, `title`, `aria-label`, `alt`, `data-confirmar`, `data-titulo` y `label`), ni lo que esté entre `<!--terminos:no-->` y `<!--terminos:si-->`. Esas marcas protegen el **formato de Accidente** (en el expediente de la novedad y en su impresión: el formulario no se toca) y las pastillas de registros de antes. Un atajo evita las expresiones regulares cuando el texto no tiene ninguna palabra que cambiar.

Explícito (no por sustitución): Configura tu empresa («Captura tus zonas, pisos y departamentos…»), la lista sugerida de servicios de Reservas (**solo hoteles**: el botón no se dibuja y `POST reservas/configuracion/sugeridos` responde 404 en otros giros; los demás dan de alta sus servicios con «Nuevo servicio»), los espacios (`Etiquetas`, como antes) y los encabezados de la exportación de accesos.

## Manual por giro

`App\Services\Manual\Manual` arma cada página para el giro de la empresa activa:

1. **Variantes:** bloques que solo aplican a algunos giros:

   ```
   <!-- solo: hotel -->
   - **Huésped:** nombre y, en **Más detalles**, ...
   <!-- fin -->
   <!-- solo: condominio, fraccionamiento -->
   - **Visita:** ...
   <!-- fin -->
   ```

   `Manual::segunGiro()` quita siempre las marcas y deja el contenido solo si el giro está en la lista (en GitHub los comentarios no se ven).
2. **Sustitución:** el HTML de la página y el texto de cada apartado (índice, búsqueda, palabras del filtro en vivo, fragmentos de resultados) pasan por `Terminos`. Un condominio que busca «residente» encuentra la Bitácora de accesos.
3. **Anclas:** los `id` de los apartados salen del texto original de la página (antes de sustituir), así que un enlace a `accesos#como-registrar-un-ingreso` funciona en todos los giros. Un apartado que solo existe en algunos giros (p. ej. «Cómo registrar a quien viene a Recursos Humanos», que no aparece en residenciales) simplemente no está en los demás.

Las cachés (`manual.pagina.*`, `manual.texto.*`) incluyen el giro en la clave.

## Datos demo por giro

`plataforma:demo` (trait `App\Console\Commands\Concerns\DemosPorGiro`) crea, además de Hotel Demo (intacto), dos empresas aparte. Cada parte solo la primera vez (si la tabla de esa empresa ya tiene datos, no hace nada); una falla no detiene el resto. Las cuentas nuevas reciben la misma contraseña que las de Hotel Demo; su correo es `usuario@<giro>.demo.local`.

| Empresa | Giro | Sedes | Usuarios | Datos |
|---|---|---|---|---|
| Corporativo Demo | corporativo | Corporativo Demo Torre Reforma (`TOR`), Corporativo Demo Planta Querétaro (`PLA`) | `corp.admin.demo` (Administrador), `corp.jefe.demo` (Jefe de seguridad), `corp.agente.demo` (Agente, Torre Reforma) | Torre Reforma › Piso 10 › oficinas 1001–1004 y Sala de juntas Roble; Nave 1 en la planta; 6 departamentos; 5 colaboradores; 5 accesos (colaborador y visitante en sitio, proveedor pendiente, contratista y visitante finalizados ayer); Sala de juntas Roble reservable con una junta mañana; un incidente. |
| Condominio Demo | condominio | Residencial Demo Norte (`NOR`), Residencial Demo Sur (`SUR`) | `condo.admin.demo` (Administrador), `condo.jefe.demo` (Jefe de seguridad), `condo.agente.demo` (Agente, Norte) | Torre A › Piso 1 y 2 › departamentos 101–104 y 201–204; Áreas comunes (Salón de usos múltiples, Asador, Alberca); Torre B en Sur; Administración, Mantenimiento y Seguridad; 5 residentes; 5 accesos (residente en sitio, visita al 101, servicio de limpieza pendiente, contratista y visita al 201 finalizados); Salón de usos múltiples (con una asamblea mañana) y Asador reservables; un incidente. |

Para regenerarlos en QA basta con que corra `plataforma:demo` (lo hace el despliegue de QA una vez por versión). Los usuarios se documentan en `docs/tecnico/despliegue.md`, «Usuarios demo (QA)».

## Pruebas

`tests/Feature/Seguridad/AdaptacionPorGiroTest.php`: hotel y empresa sin giro idénticos; corporativo sin Huésped (pantalla y servidor), exportación con sus palabras, registros de antes con su nombre; condominio con Residente, Visita, Servicio, departamento de la visita, motivo validado, menús y Objetos olvidados; fraccionamiento con casas; sustitución (artículos, mayúsculas, sin tocar código ni valores ni lo marcado); manual con términos, anclas y búsqueda; lista sugerida de Reservas solo para hoteles; demo por giro repetible sin tocar Hotel Demo.

## Límites conocidos (siguientes etapas)

- La sustitución cambia también palabras escritas por los usuarios que aparezcan en pantalla (p. ej. una descripción que diga «habitación» en un condominio). Las capturas libres (`<textarea>`) y los valores de los campos no se tocan.
- Las respuestas JSON (búsquedas en vivo) y los archivos descargados (CSV, Excel) no pasan por la sustitución; los tipos de acceso sí salen con el nombre del giro porque vienen de `etiquetaTipo()`.
- Los correos de tareas programadas que se mandan sin empresa activa salen con las palabras de siempre.
- La app móvil recibe los nombres del menú sin sustituir.
