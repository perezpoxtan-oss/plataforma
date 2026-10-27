# Quién decide en la caseta y los giros residenciales (Etapa 5)

Plan de correcciones, Etapa 5: **C-10 → C-30 → C-09 → C-28 → C-34** (decisiones DD-12, DD-14 y DD-15). ADR-0021.

Usa lo que ya existía, sin duplicar reglas:

- la **cobertura** de la Etapa 3 (`App\Services\Autorizaciones\Cobertura`, `Autorizaciones::aQuienAvisar()` y `departamentosQueAtiende()`), con la delegación en cadena (`CadenaDelegaciones`);
- el **motor de relojes** de la Etapa 4 (`MotorRelojes`, un reloj nuevo `TicketsDeDepartamento`) y el escalamiento de la caseta (`Escalamiento`).

## C-10 · Residentes y Personal (DD-14, opción B)

| Qué | Dónde |
|---|---|
| `colaboradores.tipo` (`personal` de fábrica, `residente`) y `colaboradores.espacio_id` (la vivienda) | Migración `2026_11_14_000100_residentes_y_viviendas` |
| Scopes `Colaborador::personal()` y `residentes()`; `esResidente()`; relación `vivienda()` | `app/Models/Colaborador.php` |
| **Padrones → Residentes**: lista, alta corta (vivienda, nombre, apellidos y teléfono; la sede sale de la vivienda), editar, Dar de baja / Reactivar | `Padrones\ResidenteController`, `Services\Colaboradores\Residentes`, `padrones/residentes/index.blade.php`, rutas `residentes.*` |
| Menú: «Residentes» en Padrones solo en condominio y fraccionamiento (pantalla personal de `ConstructorMenu`, permisos del directorio `colaboradores.*`) | `ConstructorMenu::conPantallasPersonales()` |
| El directorio (Estructura → «Personal» en residenciales), Usuarios y Pases buscan solo al personal (`AdministradorColaboradores::buscar(..., soloPersonal: true)`); una cuenta no se vincula a un residente | `ColaboradorController`, `UsuarioController::validarColaborador` |
| Informes (Tablero, Ejecutivo, Tendencias) cuentan solo al personal; en residenciales Tendencias muestra «Viviendas más visitadas» | `Services\Informes\*` |
| Configura tu empresa: «Personal de la administración» y «Residentes y viviendas» | `AsistenteArranque` |
| ARCO: el residente sale como «Residente … se rectifica en Residentes»; su vivienda (`espacio_id`) está en el inventario | `DatosDePersona`, `InventarioDatosPersonales` |

**Terminología** (`Terminos`, `rubros.terminologia`): en condominio el nivel del árbol se llama **Vivienda** (antes «Departamento», que chocaba con el módulo Departamentos); en condominio y fraccionamiento «Colaborador» se dice **Personal** (antes «Residente»), con plural sin cambio («el personal»; `Terminos::plural()`), artículo en singular («los colaboradores» → «el personal») y frases fijas («Un colaborador» → «Alguien del personal», «Colaborador que Ingresa» → «Persona que ingresa»). El tipo de acceso `colaborador` se ofrece como «Residente o personal» y la tarjeta dice «Residente» o «Personal» según la persona. Abreviatura de la pastilla: «Viv.». Si una empresa tenía «Residente» guardado como palabra del colaborador, se lee como «Personal».

**Datos:** en las empresas residenciales, quien no tiene cuenta vinculada pasa a residente; la vivienda se deduce del número (`R-101` → `101`, `R-B101` → `B-101`) solo si hay **una** vivienda con ese número en su sede. La terminología solo cambia si conserva el valor de fábrica. `down()` regresa la terminología y quita las columnas.

> Las llaves foráneas nuevas se agregan con `Restricciones::agregarForanea()` (y se quitan con `quitarForanea()`): en SQLite (pruebas) no reconstruyen la tabla, así que no se pierden las reglas CHECK ni las llaves que apuntan a ella. Aun así, la migración lee las cuentas vinculadas **antes** de cambiar la tabla.

## C-30 · Lugares desde el catálogo

`App\Services\Espacios\Lugares` es la única búsqueda de lugares: `opciones()`, `buscar()`, `valido()`, `camino()` («Torre A › Piso 1 › 101»), `porTexto()` (texto que coincide con una sola área específica de la sede) y `residentesDe()`.

- **Ruta** `GET /lugares/buscar` (`lugares.buscar`, limitada a 120/min) para quien captura en caseta, incidentes, reservas o el directorio, dentro de sus sedes.
- **Caseta:** `accesos.espacio_id` (migración `2026_11_14_000200`): habitación del huésped (`habitacion_id`), vivienda (sale del residente) y área de trabajo (`area_espacio_id`; el texto guarda el camino). Si no está en el catálogo, se queda el texto y la tarjeta lo marca «sin catálogo». Datos de antes: la habitación de texto se liga si coincide con una sola área específica de su sede.
- **Novedades:** «¿Dónde pasó?» (`lugar_id`) llena el Área General (piso o edificio) y la habitación específica; «Detalle del lugar» (`ubicacion`) es opcional; sin elegir de la lista se queda lo escrito (`lugar_texto`). Se pide al menos uno. El Accidente que nace de un Siniestro lleva el lugar del siniestro (ya no «Ver detalle…»).
- **Personas:** el duplicado del padrón en la caseta ignora el tipo (la misma persona como visita y luego proveedor).
- No se hicieron (ver resultado de la etapa): sugerencias vehículo ↔ conductor, acompañantes del padrón y el catálogo único de identificaciones.

## C-09 · «¿A quién viene?» (DD-12)

Un buscador (`seguridad/accesos/_a-quien.blade.php`, JS en `plataforma.js`) reemplaza a «Host — ¿Quién lo citó?», «¿A qué departamento viene?», «¿A quién visita?», «¿A qué departamento va?» y la vivienda de la visita. Sugerencias: `GET /accesos/buscar?que=destino&contexto=visita|proveedor|huesped` (`AQuienViene::sugerencias()`, con `cuenta` y `telefono` de cada persona en dos consultas fijas).

Lo elegido viaja como `a_quien`: `c:ID` persona, `d:ID` área, `e:ID` lugar, `h:ID` huésped en sitio; sin clave, `a_quien_texto`. `AQuienViene::aplicar()` lo traduce a los campos de siempre (`visita_colaborador_id`, `host_colaborador_id`, `departamento_id`, `habitacion`, `espacio_id`, `persona_visita`), así las reglas de antes, los formularios viejos y la app siguen funcionando. **El departamento se deduce de la persona.**

Motivos (`MotivosVisita`): «Visita a colaborador» y «Visita a departamento» son un solo motivo, «Visita a persona o área» («Visita» en residenciales, donde es el único y viene marcado). El registro guarda `colaborador` o `departamento` según lo elegido; `departamento` se sigue aceptando.

| A quién viene | Quién autoriza | Dónde |
|---|---|---|
| Persona con cuenta (personal) | **La persona** (`autorizaciones.colaborador_id`, migración `2026_11_14_000300`). Si no contesta, el escalamiento pasa a los responsables de su departamento (suplente) y al jefe de seguridad. Proveedor y contratista siempre; visitas, si la empresa pide autorización (si no, aviso `visita_llego`). | `RecepcionEnCaseta::preparar()`, `Autorizaciones::solicitarVisita()`, `Escalamiento::aQuienEscalar()` |
| Persona sin cuenta o área | El responsable del departamento, como antes (C-08, DD-11). | sin cambio |
| Residente, vivienda o «sin catálogo» (residenciales); huésped (hotel) | **La caseta llama**: «Llamé y autorizó · dejar pasar» (`llamada=autorizo` → en sitio, `autorizacion=llamada`, quién y cuándo) o «No autorizó» (`llamada_no` → finalizado, sin gafete). Auditoría `accesos.llamada_*`. Sin `llamada` en la petición (formularios de antes), sigue la regla de siempre. | `RegistroAccesos::registrar()` |

La persona citada responde su propia visita sin la bandeja de Autorizaciones: `Autorizaciones::esLaPersonaCitada()`, `limitar()` y `pendientesPara()` la incluyen; Mis pendientes la cuenta en «Autorizaciones por responder» y la lleva a la solicitud. Es una regla de **pertenencia** (como Mi cuenta), no de rol.

`accesos.visita_colaborador_id` es «a quién viene» para todos los tipos; `host_colaborador_id` se sigue llenando igual (la migración copia el host de los registros de antes y `down()` lo regresa).

## C-28 · Canalizar a un departamento (DD-15)

- `novedades.departamento_id`, `departamento_en`, `departamento_atendido_en/_por` (migración `2026_11_14_000400`).
- «O a un departamento» al despachar y en el expediente (`CanalizarDepartamento::valido()`: activo y de la sede).
- Aviso `novedad_departamento` a `Autorizaciones::aQuienAvisar()` (cobertura y delegación de la Etapa 3).
- **Tickets de mi departamento** (`NovedadDepartamentoController`, rutas `novedades.departamento.*`): lista, detalle de solo lectura, «Agregar nota» (Minuto a Minuto con el nombre del departamento) y **Atender** (avisa `novedad_departamento_atendida` a quien lo levantó y a la persona de Seguridad). Acceso por cobertura, no por permisos de la Bitácora. Menú: Operación → Incidentes, para quien responde por algún departamento.
- **Reloj:** `TicketsDeDepartamento` (clave `novedades_departamento`) con las horas por categoría de los tickets canalizados; suplente, el jefe de seguridad de la sede. Renglón «Tickets de tu departamento» en Mis pendientes e Inicio.
- **Accidente de un colaborador:** si en la sede hay un departamento de Recursos Humanos (por nombre: «Recursos Humanos», «RR. HH.», «Capital humano») con quien lo atienda, se le canaliza solo. El formato no cambia.

## C-34 · Reservas

- `reservas.residente_id` y `vivienda_id`; `reserva_ordenes.sin_quien_atienda` (migración `2026_11_14_000500`).
- En residenciales: «¿Para quién es? Para un residente / Uso de la administración» con el residente (y su vivienda); montaje y servicios van en **Avanzado** (también cuando el catálogo no tiene servicios).
- Departamento que solicita, servicio del catálogo y «Otro servicio»: solo departamentos **activos** (el que ya tenía se conserva).
- Orden sin quien la atienda (sin responsable del departamento ni responsable general): se marca y avisa a quien configura (`reserva_orden_sin_quien`, no se silencia). Configuración muestra los servicios sin departamento activo.
- ARCO encuentra las reservas del residente.

## Demo de fraccionamiento

`plataforma:demo` crea **Fraccionamiento Demo** (sede «Las Lomas», `LOM`) con `frac.admin.demo` (Administrador, responsable de Mantenimiento y Administración), `frac.jefe.demo` (Jefe de seguridad) y `frac.agente.demo` (Agente): calles, manzanas y casas, Club (Casa club reservable, Alberca, Cancha), 4 residentes con casa y teléfono, 2 del personal, 3 accesos (residente, visita con llamada y servicio de gas) y un incidente. El condominio demo ahora crea sus residentes con vivienda y 2 del personal.

## Pruebas

`tests/Feature/Seguridad/EtapaCincoTest.php`: migraciones con datos (ida, vuelta e ida), residentes y personal (alta corta, menú, directorio, Usuarios, baja, Configura tu empresa), términos, visita al residente con llamada (constancia, no autorizó, sin catálogo, servicio de gas, sugerencias, Tendencias), la persona citada con cuenta (aviso, Mis pendientes, escalamiento al departamento, respuesta, área, proveedor, «Llegó tu visita»), hotel (habitación del catálogo, visita a huésped con llamada, área de trabajo, `lugares.buscar`), canalizar a Mantenimiento (aviso, reloj, Mis pendientes, solo su ticket, nota, Atender, expediente, lugar), accidente de colaborador a RR. HH., reservas por vivienda (residente, departamento de baja, orden sin quien, configuración, ARCO) y el demo de fraccionamiento.
