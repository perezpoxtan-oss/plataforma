# Lección 46 · Informes: Tablero, Bitácora del día, Tendencias e Informe ejecutivo

**Duración:** 30 minutos · **Para:** Director, Administrador, Jefe de seguridad, Supervisor y Asistente.

Aprenderás:

1. A leer el **Tablero** y abrir el detalle de cada ficha.
2. A revisar e imprimir la **Bitácora general del día** para entregar el turno.
3. A comparar periodos en **Tendencias**, leer si cada cifra **mejoró** o **empeoró**, imprimir y exportar una sección.
4. A leer, imprimir y exportar el **Informe ejecutivo**.
5. Por qué cada quien ve cifras distintas (alcance) y por qué **Imprimir** es un permiso aparte.

## Antes de empezar

Usa la empresa de práctica con `director.demo` (toda la empresa), `jefe.demo` (Jefe de seguridad), `supervisor.demo` (una sede) y `agente.demo` (caseta).

## Práctica 1 · El Tablero (director.demo)

1. En **Inicio** toca **Ver el tablero…** (o **Informes → Tablero**).
2. Toca la ficha **Personas en sitio ahora**: revisa la gráfica por tipo de acceso, abre **Ver / Ocultar listado de registros** y toca **Ir a la Bitácora de accesos**.
3. Regresa y abre **Lost & Found por vencer / vencidos**; el botón te lleva a los **urgentes**.
4. Elige una sola sede en **Sede** y compara los números.

Comprueba: cada ficha abre su detalle en una ventana y el enlace lleva al módulo filtrado.

## Práctica 2 · Alcance (supervisor.demo y agente.demo)

1. Entra como `supervisor.demo` al Tablero: las cifras son solo de su sede y no hay selector de sede.
2. Entra como `agente.demo`: no aparece el menú **Informes** ni el enlace en Inicio.

Comprueba: cada quien ve lo de su alcance; si a un rol le quitas «ver» de un módulo (por ejemplo Bitácora de accesos), su ficha desaparece del Tablero.

## Práctica 3 · Bitácora del día (jefe.demo)

1. **Informes → Bitácora del día**. Revisa los conteos de arriba y la línea de tiempo.
2. Cambia **Día** a ayer y luego elige **Tipo: Novedades**.
3. Toca **Imprimir** y revisa las líneas de firma **Entrega el turno / Recibe el turno**.

Comprueba: las horas están en la hora de la sede y los tickets se pueden abrir desde la línea de tiempo.

## Práctica 4 · Tendencias (director.demo)

1. **Informes → Tendencias**, **Periodo: Este mes**, **Aplicar**.
2. En **Accesos**, lee la flecha y la frase de **Ingresos registrados** («subió» o «bajó», sin color: es volumen de trabajo) y la línea punteada del periodo anterior.
3. En **Novedades**, compara **Tickets abiertos en el periodo** y **Tiempo promedio de cierre**: si suben, salen en rojo con «empeoró»; si bajan, en verde con «mejoró».
4. Elige **Personalizado** con dos semanas y marca solo una sede.
5. Toca **Exportar** en **Transporte** y abre el archivo en Excel: la columna **Lectura** dice «mejoró», «empeoró», «subió» o «bajó».
6. Toca **Imprimir** → **Imprimir o guardar como PDF**: la hoja trae las mismas frases y gráficas.

Comprueba: el encabezado dice el periodo «contra» el anterior, el color coincide con la frase y el archivo trae las cifras, la serie y los desgloses.

## Práctica 5 · Informe ejecutivo (director.demo)

1. **Informes → Informe ejecutivo**. Recorre los bloques y abre el listado de **Pases creados**.
2. Toca **Imprimir** → **Imprimir o guardar como PDF** → **Guardar como PDF**.
3. Toca **Exportar** para el archivo de Excel.

Comprueba: no aparecen CURP, RFC, NSS ni teléfonos; en Candidatos solo hay cifras.

## Práctica 6 · El permiso Imprimir (admin.demo y jefe.demo)

1. Con `jefe.demo` abre el **Tablero**: arriba está **Imprimir**. Tócalo y revisa la hoja con las fichas.
2. Con `admin.demo`, en **Roles y permisos → Avanzado**, quita **Imprimir** del **Tablero** al rol «Jefe de seguridad» y guarda.
3. Regresa con `jefe.demo`: el Tablero se sigue viendo, pero ya no aparece **Imprimir**.
4. Vuelve a dar el permiso.

Comprueba: **Ver** e **Imprimir** son casillas separadas en los cuatro informes.

## Repaso

- El Tablero es «ahora»; Tendencias compara periodos; el Informe ejecutivo resume para Dirección; la Bitácora del día es la hoja del turno.
- Cada cifra pide el permiso del informe **y** el del módulo de donde sale.
- Tendencias y el Informe ejecutivo se recalculan cada 5 minutos; **Actualizar cifras** lo hace al momento.
- En Tendencias, verde = mejoró y rojo = empeoró según la cifra (más robos es malo, más contratados es bueno); las cifras de volumen no llevan color.
- **Imprimir** se da aparte de **Ver** en cada informe.
