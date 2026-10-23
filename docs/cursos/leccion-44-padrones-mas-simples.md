# Lección 44 · Padrones más simples: dar de baja, reactivar, imprimir y verificar con un solo camino

**Duración:** 35 minutos · **Para:** Administradores, jefes de seguridad, supervisores, caseta y Recursos Humanos.

Aprenderás:

1. Que en **todos** los padrones la baja se llama igual: **Dar de baja**, **Reactivar** y **DE BAJA**.
2. A recibir en la **campana** las altas que registra la caseta, y que la caseta se entera del resultado.
3. A **unir** dos fichas de la misma persona.
4. Que **Reactivar** una llave, un gafete o un equipo es lo mismo que **Recuperado** en su voucher, y a firmar la baja en la pantalla también en Gafetes y Equipos.
5. A usar el **siguiente paso** después de guardar (por ejemplo **Imprimir este lote**).
6. A elegir cómo imprimen los padrones (hoja propia o plantillas de Etiquetas QR) y a no olvidar la **escala 100 %**.
7. A corregir un estacionamiento que marca ocupado un lugar que ya se liberó.

## Antes de empezar

Usa la empresa de práctica con `admin.demo` (Administrador), `jefe.demo` (Jefe de seguridad de Centro), `supervisor.demo`, `agente.demo` (caseta de Centro), `agente2.demo` (caseta de Playa), `rh.demo` (Recursos Humanos) y `director.demo`.

## Práctica 1 · Un solo vocabulario (admin.demo)

1. Abre **Padrones → Empresas externas**, **Gafetes**, **Equipos de seguridad**, **Estacionamientos y zonas de descarga** y **Rutas de transporte**. En todas el botón rojo dice **Dar de baja** y lo dado de baja se lee **DE BAJA**.
2. Abre **Estructura → Matriz de permisos**: la columna que antes decía «Eliminar» ahora dice **Dar de baja**. Borrar para siempre sigue siendo **Eliminar definitivamente** (solo el Administrador).
3. En **Recursos Humanos → Catálogos → Puestos** lee bajo el título: «El puesto es el rango; el departamento es el área».

Comprueba: en ninguna pantalla de padrones aparecen «Desactivar», «Suspender», «Vetada» ni «CXC».

## Práctica 2 · Altas por verificar en la campana (agente.demo y jefe.demo)

1. Con `agente.demo` abre **Operación → Caseta → Bitácora de accesos**, empieza un ingreso de visitante y registra a una persona nueva con **Registro Rápido de Persona** (por ejemplo «Rosa Uc Canché»). Toca **No es ninguno: registrarlo como nuevo** si te pregunta.
2. Con `jefe.demo` mira la **campana**: «Alta por verificar: Rosa Uc Canché». Toca **Verificar**: llegas al Padrón de personas en sus pendientes.
3. Toca **Verificar** en la ficha, elige **Rechazar** y escribe el motivo «Nombre mal escrito».
4. Con `agente.demo` mira la campana: «Tu alta «Rosa Uc Canché» fue rechazada» con el motivo.

Comprueba: el aviso del jefe ya no tiene el botón **Verificar** (se resolvió).

## Práctica 3 · Colaboradores por verificar (agente.demo y rh.demo)

1. Con `agente.demo`, en **Recursos Humanos → Colaboradores**, toca **Registro rápido**, escribe «Carlos Dzib» y su sede, y toca **Guardar**. Queda **POR VERIFICAR**.
2. Con `rh.demo` mira la campana y **Mis pendientes** → **Colaboradores por verificar**.
3. Toca **Verificar**, asigna un número y toca **Guardar y verificar**.
4. Con `agente.demo`: la campana dice «Tu alta «Carlos Dzib» quedó verificada».

## Práctica 4 · Unir dos fichas de la misma persona (admin.demo o jefe.demo)

1. En el **Padrón de personas** registra «Ana López Uc» y luego «Ana Lopez» con un folio de INE.
2. En la ficha **Ana Lopez** toca el botón azul **Unir con otra ficha**, elige **Ana López Uc** y toca **Guardar unión**.
3. Abre la ficha de **Ana López Uc**: ahora tiene el folio. **Ana Lopez** quedó fuera de uso, sin borrarse.

Comprueba: con `supervisor.demo` no aparece el botón (puede editar, pero no dar de baja).

## Práctica 5 · Baja con firma digital y Reactivar = Recuperado (admin.demo)

1. En **Padrones → Gafetes** toca la **X roja** de un gafete disponible. Elige **Dañado**, deja **Se le cobra al colaborador** sin marcar, elige **Firma digital**, firma en el recuadro y toca **Dar de baja**.
2. La ficha dice **DE BAJA · DAÑADO** y el folio de su voucher. Tócalo: llegas a **Vouchers de reposición** justo en ese voucher, **Firmado digitalmente**.
3. Regresa y toca **Reactivar**: «… Su voucher VR-… quedó «Cancelado por recuperación».»
4. Ahora da de baja otro gafete **con cobro** (monto y colaborador) y toca **Reactivar**: llegas a Vouchers con la ventana **Recuperado** abierta. Indica si ya se cobró y confirma.

## Práctica 6 · Siguiente paso e impresión (admin.demo)

1. En **Gafetes** genera un lote de 3. Debajo del mensaje toca **Imprimir este lote**: se abren justo esos 3.
2. En la hoja lee el recuadro amarillo **Antes de imprimir** (Tamaño real / Escala 100 %).
3. Toca **Imprimir con una plantilla de Etiquetas QR**: se abre la hoja de Etiquetas QR con esos gafetes y queda en el **Historial**.
4. En **Etiquetas QR → Plantillas**, en **¿Cómo imprimen Llaves, Gafetes, Vehículos y Equipos?**, elige **Plantillas de Etiquetas QR** y toca **Guardar**. Vuelve a **Gafetes** e imprime uno: ahora va directo a Etiquetas QR. Regresa la opción a **Hoja propia de cada padrón** para dejarla como estaba.
5. En **Padrón vehicular** registra un vehículo: el siguiente paso es **Imprimir su calcomanía**.

## Práctica 7 · Estacionamiento desfasado (agente.demo)

1. Abre **Padrones → Estacionamientos y zonas de descarga**. En **Estacionamiento Huéspedes** toca **¿Quién ocupa?**.
2. Supón que `QRR4410` ya se fue: toca **Dar salida** y confirma.
3. La ocupación baja un lugar y en la **Bitácora de accesos** ese acceso aparece con su salida.

Comprueba: con `director.demo` no aparece **¿Quién ocupa?** (solo consulta).

## Práctica 8 · Empresas: la correcta y de regreso a tu sede (admin.demo y supervisor.demo)

1. En **Padrón de personas → Registrar Persona** elige *Proveedor*, deja **-- Otra empresa (escríbela abajo) --** y escribe «abarrotes del caribe». Guarda: la persona queda en el personal de **Abarrotes del Caribe** (la que se llama igual), no en la de «S.A. de C.V.».
2. Escribe ahora «Abarrotes del Caribe S de RL»: como se parece a las dos y ninguna es exacta, te pide elegirla en **Empresa que representa**.
3. En la ficha de una empresa, pestaña **Personal**, toca **Agregar persona** y guarda: regresas a la misma pestaña **Personal** (igual con **Flotilla**).
4. Como **supervisor.demo**, en **Empresas externas** toca **Quitar de mi sede** en una empresa y acepta: deja de verse en tu lista y arriba sale **Agregar a mi sede** para regresarla. Tócalo.

## Repaso

- Una sola palabra para la baja en toda la plataforma; nada se borra.
- La campana avisa a quien verifica y a quien registró.
- Reactivar desde la ficha y Recuperado en el voucher son el mismo hecho.
- Después de guardar, mira el **Siguiente paso**.
- Imprime siempre a **Tamaño real / 100 %**.
