# Lost & Found y Robo — Seguimiento

Operación → Caseta y Control → **Lost & Found** y **Robo — seguimiento**.

- **Lost & Found** es el archivo de todos los objetos encontrados: dónde están guardados, cuántos días llevan y a quién se entregaron.
- **Robo — Seguimiento** reúne todos los casos de robo para darles seguimiento sin buscarlos entre las demás novedades.

Los objetos y los casos **se registran en la Bitácora de Novedades** (ticket de *Lost & Found* o de *Robo*). Aquí se consultan, se entregan y se imprimen.

## Lost & Found

![Archivo de Lost & Found](img/lost-found-y-robo/01-archivo.png)

### Cómo se lee una fila

- **LF-000123 — OBJETO**: el folio del artículo y qué es. A un lado, su tipo de valor (Ropa, Electrónico…).
- Debajo: cuándo se encontró, **cuántos días lleva en resguardo** (de cuántos permitidos), marca y color, dónde se encontró y **Bodega**: dónde está guardado.
- El **color** de la fila es el semáforo:

| Color | Qué quiere decir |
|---|---|
| Verde | En tiempo. |
| Ámbar | Por vencer: ya pasó el 70 % de sus días. |
| Rojo | Vencido: hay que decidir si se entrega, se dona o se destruye. |

- Si un huésped ya lo reportó como perdido, verás **Reporte de pérdida RP-…** con su nombre.
- A la derecha: el estatus (**En Resguardo**, **Devuelto al Huésped**, **Donado a Colaborador**…), el ojo (ver la ficha), la etiqueta (imprimir) y **Cerrar / Entregar**.

### Buscar y filtrar

- Toca un filtro: **Todos**, **En resguardo**, **Solo urgentes / vencidos**, **Devueltos** o **Donados / destruidos / beneficencia**. El número dice cuántos hay.
- Escribe el folio, el objeto, la marca, el color, la habitación o la bodega y toca **Buscar**. **Limpiar** quita la búsqueda.
- Puedes elegir la sede (si ves varias) y el tipo de valor.
- **Sin artículos capturados aún**: tickets de Lost & Found a los que nadie les ha anotado los objetos. Toca **Completar** para hacerlo en la Bitácora de Novedades.

![Solo urgentes](img/lost-found-y-robo/02-urgentes.png)

### Escanear la etiqueta de la bolsa

En **Escanear etiqueta de la bolsa** escanea el QR de la etiqueta (con el lector o con la cámara del celular, botón del código QR) o escribe el folio `LF-000123`. Se abre la ficha del artículo.

### Entregar o cerrar un artículo

1. Toca **Cerrar / Entregar** en la fila (o en la ficha del artículo).
2. Elige **¿Cómo se cierra el artículo?**:
   - **Devuelto en persona**: elige quién recibe.
     - *Huésped o persona externa*: escribe su nombre, el tipo de identificación y, si quieres, su correo. Si un huésped reportó la pérdida, su nombre ya viene escrito: **revisa que sea la misma persona**. Con **Registrar en el Padrón** la registras con su identificación.
     - *Colaborador*: escanea su gafete o escribe su número de empleado.
   - **Enviado por paquetería**: nombre de quien recibe, paquetería y número de guía.
   - **Donado a colaborador**: escanea el gafete del colaborador.
   - **Destruido**.
   - **Entregado a beneficencia**: escribe la institución.
3. Pide la **firma** en el recuadro (quien recibe, o quien autoriza la donación o destrucción). Si cambias el tipo de cierre, la firma se borra y se vuelve a pedir.
4. Escribe comentarios si hace falta y toca **Registrar Cierre**.

![Cerrar en persona](img/lost-found-y-robo/05-cerrar-persona.png)
![La firma](img/lost-found-y-robo/05b-cerrar-firma.png)

- Sin firma no se puede registrar: el recuadro se pone rojo.
- Si falta un dato, el aviso sale **dentro** de la ventana.
- Un artículo ya cerrado **no se vuelve a cerrar**.
- El cierre queda anotado en el historial del ticket (Minuto a Minuto).

![Paquetería](img/lost-found-y-robo/06-cerrar-paqueteria.png)
![Donado a colaborador](img/lost-found-y-robo/07-cerrar-donado.png)
![Falta la firma](img/lost-found-y-robo/08-cerrar-falta-firma.png)

### Ficha del artículo

Todo sobre un artículo: dónde está, de qué ticket viene (con el **Acuse de Recibo**), reportes de pérdida y robos vinculados, y si ya se entregó: a quién, cuándo, quién lo registró y la firma.

![Ficha de un artículo en resguardo](img/lost-found-y-robo/09-ficha.png)
![Ficha de un artículo entregado](img/lost-found-y-robo/10-ficha-entregado.png)

### Etiqueta de la bolsa

Toca la etiqueta (en la fila o en la ficha) y **Imprimir Etiqueta**. Pégala en la bolsa: al escanearla se abre la ficha del artículo.

![Etiqueta](img/lost-found-y-robo/11-etiqueta.png)

### Auditoría de Inventario

**Auditoría** imprime la lista de lo que debería estar en bodega hoy. Puedes elegir la sede y las fechas en que se encontraron y tocar **Aplicar**. Imprímela, marca cada artículo que encuentres y anota las diferencias. Al final firman quien contó y quien cotejó.

![Auditoría](img/lost-found-y-robo/12-auditoria.png)

### Días de Resguardo

Cuántos días puede estar guardado un artículo de cada tipo antes de ponerse en rojo. Aplican a todas las sedes de la empresa. Ahora se cambian en **Estructura → Configuración → Lost & Found: días de resguardo** (ver [Configuración](configuracion.md#lost--found-días-de-resguardo-administrador-de-la-empresa)). Quien puede cambiarlos ve arriba del archivo el enlace **Configurar en Estructura → Configuración**:

![Enlace a Configuración](img/ajustes4/lf-2-enlace-lost-found.png)

Los demás ven los días de cada tipo en el filtro de tipo de valor y en cada artículo («12 día(s) en resguardo de 30»). Así lo ve el Agente en el celular, sin el enlace:

![Lost & Found del Agente](img/ajustes4/lf-6-agente-celular.png)

## Robo — Seguimiento

![Robo — Seguimiento](img/lost-found-y-robo/20-robos.png)

- Cada caso muestra el #ticket, dónde fue, qué se llevaron, el valor estimado y sus etiquetas: **Abierto / Resuelto**, **Con sospechoso**, **Sin parte a policía**, **Gerencia notificada**, **En Legal**. Si se encontró lo «robado», dice **Vinculado con el hallazgo LF-…**.
- Filtros: **Todos**, **Abiertos**, **Sin parte a la policía**, **Con sospechoso**. Busca por objeto, lugar o #ticket.

### Dar seguimiento a un caso

1. Toca **Abrir Expediente** (o **Ver Expediente** si solo puedes consultar).
2. Escribe una **nueva nota** (se agrega al historial, no lo reemplaza).
3. Completa **1. Circunstancias**, **2. Sospechoso**, **3. Testigos** y **4. Canalización**.
4. **Buscar Coincidencias en Lost & Found** revisa si ya se encontró algo parecido; con **Vincular** lo ligas al caso.
5. Para cerrarlo elige **Resuelto y Cerrado** y escribe cómo se resolvió. Toca **Guardar Cambios**.

Un caso resuelto ya no se edita: se reabre desde la Bitácora de Novedades con el motivo. El botón de la impresora imprime el expediente.

![Expediente de Robo](img/lost-found-y-robo/22-robo-expediente.png)
![Buscar coincidencias](img/lost-found-y-robo/23-robo-coincidencias.png)

## En el celular

Todo funciona igual en el celular: filtros y botones grandes, y la ventana de cierre ocupa la pantalla.

![Archivo en el celular](img/lost-found-y-robo/30-celular-archivo.png)
![Cerrar en el celular](img/lost-found-y-robo/31-celular-cerrar.png)
![Robo en el celular](img/lost-found-y-robo/34-celular-robo-expediente.png)

## Modos Sol y Noche

![Noche](img/lost-found-y-robo/40-noche-archivo.png)
![Sol](img/lost-found-y-robo/41-sol-cerrar.png)

## Quién puede hacer qué

| Rol | Lost & Found | Robo |
|---|---|---|
| Agente | Ve su sede, entrega y cierra con firma, imprime. No cambia los Días de Resguardo. | Ve y da seguimiento en su sede. |
| Supervisor / Jefe de seguridad | Igual que el Agente, en su sede. | Igual, en su sede. |
| Director | Consulta e imprime (no entrega). | Solo consulta. |
| Administrador | Todo, incluso los Días de Resguardo. | Todo. |

![Vista del Director](img/lost-found-y-robo/50-director-archivo.png)
