# Departamentos y Puestos

Réplica de `modules/departamentos` y `modules/puestos` de SEGCAT. Las pantallas, los textos y los colores son los mismos: ámbar para Departamentos y cian para Puestos.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| `hoteles_departamentos` guardaba las sedes donde el departamento **no** aplicaba (`estatus = 0`) y la tabla se borraba y reconstruía en cada edición | Se guarda en positivo: `todas_las_sedes` (incluye las sedes futuras) o la lista `departamento_sede` de sedes donde aplica. La lista se sincroniza dentro de una transacción |
| El nombre repetido se revisaba con una petición AJAX que aceptaba cualquier `id_empresa`, así que se podían consultar nombres de otras empresas | El aviso en vivo compara contra los nombres de la propia empresa que ya trae la página, sin consultar al servidor. Al guardar se valida de nuevo, sin importar mayúsculas ni espacios dobles |
| La edición se cargaba por AJAX con un HTML aparte | Se usa el mismo diálogo de alta, llenado con `data-valores`, como en el resto de la plataforma |
| Al editar un puesto se perdían sus ligas con departamentos desactivados, porque no aparecían en la lista | Las ligas con departamentos desactivados se conservan |
| Estado ON/OFF dentro del formulario y además el botón de desactivar | Solo el botón ⊘ / ↺, igual que en las demás pantallas |
| Sin auditoría | Eventos `departamentos.*` y `puestos.*` con antes y después, incluidas las sedes y los departamentos ligados |

## Modelo de datos (migración `2026_10_05_000300`)

- **`departamentos`:** `empresa_id`, `nombre`, `todas_las_sedes`, `activo` y auditoría. Índice único `(empresa_id, nombre)`.
- **`departamento_sede`:** sedes donde aplica, solo cuando `todas_las_sedes = 0`. El scope `Departamento::aplicanEn($sedeIds)` filtra los departamentos visibles para unas sedes.
- **`puestos`:** `empresa_id`, `nombre`, `tipo` (`operativo` | `administrativo`), `activo` y auditoría. Índice único `(empresa_id, nombre)`.
- **`departamento_puesto`:** relación N:M. Si un puesto no tiene departamentos ligados, aplica en cualquiera.

## Permisos

- **Consulta:** `departamentos.ver` y `puestos.ver`.
- **Cambios:** `.crear`, `.editar` y `.eliminar`; este último desactiva o reactiva.
- **Catálogos de toda la empresa** (`Organizacion\Concerns\CatalogoDeEmpresa`):
  - Para modificar se necesita alcance de **empresa**.
  - Con alcance de sede se ve en modo consulta. En Departamentos solo aparecen los que aplican en sus sedes.
  - Si alguien con alcance de sede intenta escribir directo a la ruta, recibe 403.
- Un registro de otra empresa responde 404.

## Rutas

- `GET|POST /departamentos`, `PUT /departamentos/{id}`, `PATCH /departamentos/{id}/estado`
- `GET|POST /puestos`, `PUT /puestos/{id}`, `PATCH /puestos/{id}/estado`

## Componentes compartidos nuevos (`public/js/plataforma.js`)

- **`data-valores` con arreglos:** llena listas de casillas (`name="sedes[]"`).
- **`data-oculta-si-marcado="#id"`:** una casilla que oculta un bloque mientras está marcada. Se usa en "Todas las sedes".
- **`data-nombres-existentes` + `data-aviso-nombre`:** aviso en vivo de nombre repetido.
- **`data-filtro-tipo="clave"`:** píldoras de tipo (Administrativos / Operativos) integradas al filtro genérico de fichas.

## Datos demo

`plataforma:demo` crea 7 departamentos, entre ellos *Club de Playa*, que solo aplica en Hotel Demo Playa, y 9 puestos.

## Pruebas

`tests/Feature/Organizacion/DepartamentosYPuestosTest.php`
