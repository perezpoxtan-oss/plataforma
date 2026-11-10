# ADR-0017 — Informes: Tablero, Bitácora general del día, Tendencias e Informe ejecutivo

**Fecha:** 2026-10-12 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/informes.md](../tecnico/informes.md)

## Contexto

Los cuatro informes del sistema original existían en el catálogo y en la Matriz («Próximamente») pero no tenían pantalla, y el menú Informes estaba oculto. La crítica de uso los señalaba como la pieza faltante más visible para Dirección: no había un solo lugar con las cifras del día. Restricciones: hospedaje compartido (consultas agregadas, sin procesos externos), CSP sin scripts de terceros, MariaDB en producción y SQLite en pruebas, multiempresa con alcance por sede.

## Decisión

1. **Espejo funcional** del original (mismos indicadores, nombres y periodos: 7, 30 y 90 días; semana/mes/trimestre/año/personalizado contra el periodo anterior), con la experiencia de la plataforma: fichas con diálogo, «Ver / Ocultar listado de registros», enlaces al módulo ya filtrado, hoja carta imprimible y archivo de Excel (CSV).
2. **Tablero en pantalla propia**, enlazada desde Inicio. Inicio se queda como «lo que espera tu respuesta» (ADR-0016) y no hace 8 cálculos más en cada entrada.
3. **Doble permiso**: cada cifra pide el permiso del informe y el `ver` de su módulo de origen; las sedes son la intersección de ambos alcances y del filtro. Sin permiso del módulo, la ficha o sección no se calcula.
4. **Gráficas SVG del servidor** en lugar de Chart.js: no estaba disponible localmente ni se pudo instalar desde el registro de paquetes en el entorno de trabajo; el SVG no necesita JavaScript, cumple la CSP y cada gráfica trae su tabla de datos.
5. **Consultas portables**: series con `SUM(CASE …)` por intervalos calculados en PHP en la hora local; sin funciones de fecha del motor. Recorridos de dos columnas con `cursor()` solo donde hace falta (hora del día, duraciones). Cuatro índices por empresa y fecha.
6. **Caché corta** (5 min) en Tendencias e Informe ejecutivo, con clave por empresa, alcance efectivo, sedes y periodo, y botón «Actualizar cifras». Tablero y Bitácora del día, sin caché.
7. **Sin datos sensibles**: nunca CURP, RFC, NSS, teléfonos ni CV; candidatos solo como cifras; nombres de personas solo donde el módulo de origen ya los muestra a quien consulta.
8. La versión imprimible del Informe ejecutivo pide solo `ver` (el módulo no tiene acción «imprimir» y así era en el original); la de la Bitácora del día pide `imprimir`. *(Reemplazado por decisión del dueño: las cuatro hojas piden `imprimir`; ver abajo.)*

## Consecuencias

- Migración `2026_10_28_000100_informes`: `modulos.ruta` de los cuatro e índices; su `down()` los quita. No hay tablas nuevas ni datos que convertir.
- Sin eventos de auditoría nuevos (todo es de lectura) ni avisos.
- `plataforma:demo` agrega semanas anteriores para que Tendencias tenga con qué comparar.
- Resuelto (decisiones del dueño, mapa de procesos): «Imprimir» es permiso propio en los cuatro informes (Tablero y Tendencias ganan hoja imprimible; la del Informe ejecutivo deja de pedir solo `ver`), y cada cifra de Tendencias declara si subir es bueno o malo (verde «mejoró» / rojo «empeoró», con flecha y texto). Ver [informes.md](../tecnico/informes.md).
- Pendiente (decisiones del dueño): secciones adicionales del original no migradas (valores a la vista con caja fuerte y aperturas, siniestros de Protección Civil, emergencias).
