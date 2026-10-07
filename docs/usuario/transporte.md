# Bitácora de transporte

Operación → **Bitácora de transporte**. Aquí la caseta anota cada **llegada** y **salida** del transporte de personal, y cuando la unidad **no llega**, los **taxis** que se pagaron con caja chica (con su vale para imprimir).

## 1. La lista

Al entrar ves los movimientos de **hoy**. Arriba tienes:

- **Fecha Inicio** y **Fecha Fin**, y los atajos **Hoy**, **Ayer** y **Últimos 7 días**.
- Búsqueda por placas, chofer, ruta o pasajero, y filtros por sede, tipo (llegadas o salidas), estatus y estado (vigentes, anulados o vales sin Vo.Bo.). En el celular están en **Más filtros**.
- **Exportar Excel** (si tienes permiso) y **Reportes Avanzados**.
- Un resumen: movimientos, a tiempo, retrasos, taxis y gasto en taxis.

![Lista de la bitácora](img/transporte/01-lista.png)

Cada tarjeta muestra **LLEGADA** (verde) o **SALIDA** (amarillo), la hora, el folio, la ruta y su hora programada, la sede, la unidad, el chofer y los pasajeros. Las tarjetas **rojas** son taxis: muestran el pago, el destino y si ya tienen el **Vo.Bo.** Una tarjeta gris está **ANULADA**.

![Tarjeta de un taxi](img/transporte/01b-tarjeta-taxi.png)

Botones de cada tarjeta: ver detalle (ojo), editar (lápiz) y anular (círculo tachado) o reactivar (flecha).

## 2. Registrar una llegada o salida normal

1. Toca **Registrar Movimiento**.
2. Si atiendes una sola sede, ya está elegida. Si no, elige la **Sede**.
3. **Tipo de Movimiento**: LLEGADA (a la sede) o SALIDA (hacia paraderos). El sistema ya propone el más probable por la hora.
4. **Estatus del Servicio**: *A Tiempo* o *Con Retraso*.
5. **Ruta**: ya viene elegida la ruta más cercana a la hora actual. Cámbiala si no es esa. Abajo ves la empresa transportista y el tope por taxi.
6. **Datos de la Unidad** (todo opcional): placas, chofer y número de pasajeros. Si las placas o el chofer ya están registrados, aparecen en la lista y se llenan solos marca, modelo, número económico, capacidad y teléfono. Si los pasajeros superan la capacidad sale un aviso de **sobrecupo**.
7. Toca **Guardar Registro Operativo**. Si vas a capturar otra unidad, usa **Registrar y capturar siguiente**: se guarda y la ventana se abre otra vez con la misma sede y tipo.

![Alta normal](img/transporte/02-alta-normal.png)
![Datos de la unidad con autollenado](img/transporte/02b-alta-unidad.png)

## 3. La unidad no llegó: taxis

1. En **Estatus del Servicio** elige **FALLA DE FLETERA (Uso de Taxis)**. Aparece el **Taxi 1**.
2. Captura **Placas**, **Nombre Conductor**, **Monto Vale ($)** y **Destino** (el paradero; si no existe en la lista, se agrega solo).
3. Si el monto pasa del tope de la ruta, aparece un aviso amarillo y debes escribir la **justificación**.
4. **Pasajeros de este Taxi**: escanea el gafete del colaborador (o escribe su número de empleado: aparece solo, sin Enter). Se agrega como una ficha; escanea al siguiente. Para quitar a alguien toca la **×**. Si la persona no aparece, toca **¿No aparece? Alta provisional**: Recursos Humanos la validará después.
5. El conductor firma en **Firma del taxista** y tú en **Firma del guardia** (si tu usuario no firma en pantalla, el vale impreso trae las líneas para firmar a mano).
6. ¿Fueron varios taxis? Toca **Añadir otro Taxi**. Con el bote de basura quitas uno (siempre queda al menos uno).
7. Guarda. Se crea **un vale por taxi** y aparecen los botones para **imprimir cada vale**. Si la empresa lo activó, cada vale se envía por correo para su autorización.

![Taxi con monto arriba del tope](img/transporte/03-alta-taxi.png)
![Segundo taxi](img/transporte/03b-segundo-taxi.png)

Si falta algo, los avisos aparecen **dentro de la ventana**, taxi por taxi, y no pierdes lo capturado (solo hay que volver a firmar):

![Errores dentro de la ventana](img/transporte/04-error-en-dialogo.png)
![Vales registrados](img/transporte/05-vales-registrados.png)

## 4. Detalle, edición y Vo.Bo.

El **ojo** abre el detalle: horario programado, transportista, tope, trazas (quién registró, editó, autorizó o anuló) y las **firmas**.

![Detalle de un vale](img/transporte/06-detalle.png)

**Editar** solo corrige: en un servicio normal, el número de pasajeros, *A tiempo / Retraso* y observaciones; en un taxi, el monto, el destino, la justificación, los pasajeros y observaciones. Si se equivocaron de ruta, unidad o chofer, **anula** el registro y captúralo de nuevo.

![Editar un taxi](img/transporte/07-editar.png)

Quien autoriza (por ejemplo el Director) ve **Autorizar (Vo.Bo.)** en los vales pendientes. Un vale autorizado ya no se edita. Anular no borra: el registro queda en el historial y se puede **reactivar**.

## 5. Vale de caja chica

**Imprimir Planilla** abre el «VALE DE CAJA CHICA - TAXI DE OPERACIÓN»: tres copias en una hoja (Contabilidad / Caja chica, Control interno de caseta y Operador de taxi), con las firmas capturadas y el Vo.Bo. si ya lo tiene.

![Vale de caja chica](img/transporte/08-vale.png)

## 6. Reportes y exportación

**Reportes Avanzados** filtra por sede, fechas, estatus y proveedor (la empresa transportista de la ruta), con 25 registros por página. Los totales cuentan solo los registros vigentes. **Exportar (mismos filtros)** descarga un archivo que abre en Excel.

![Reportes](img/transporte/09-reportes.png)

## 7. A quién le llega el correo de cada vale

En Dirección → **Configuración** → *Avisos por correo*, marca **Enviar cada vale de taxi…** y escribe los correos (uno por línea). Si lo dejas vacío, se envía a quienes pueden autorizar vales en esa sede.

![Configuración de avisos](img/transporte/10-configuracion-avisos.png)

## 8. En el celular, modo Sol y modo Noche

Todo funciona en el celular: botones grandes, el lector abre la cámara para leer el QR del gafete y en Android también lee NFC.

![Lista en el celular](img/transporte/11-movil-lista.png)
![Alta en el celular](img/transporte/12-movil-alta.png)
![Taxi en el celular](img/transporte/13-movil-taxi.png)
![Botones en el celular](img/transporte/13b-movil-botones.png)
![Vale en el celular](img/transporte/14-movil-vale.png)

![Modo Noche](img/transporte/15-noche-lista.png)
![Modo Sol](img/transporte/15-sol-lista.png)
![Alta en modo Noche](img/transporte/16-noche-alta.png)
![Alta en modo Sol](img/transporte/16-sol-alta.png)
![Reportes en modo Noche](img/transporte/17-noche-reportes.png)
![Reportes en modo Sol](img/transporte/17-sol-reportes.png)

## 9. Lo que ve un Agente

El Agente ve y registra solo en **su sede**, firma en pantalla y corrige registros; no anula, no autoriza vales ni exporta.

![Vista del Agente](img/transporte/18-agente-lista.png)

## 10. «¿Es alguno de estos?» mientras escribes (Ronda 5)

Al escribir las **Placas** o el **Chofer** (también en cada taxi), si lo que escribes no está tal cual en la lista, la ventana te muestra los parecidos del padrón, igual que en Control de accesos:

![Placas parecidas](img/ronda-5b/transporte-parecidos-placas.png)

![Chofer parecido](img/ronda-5b/transporte-parecidos-chofer.png)

- Si es uno de ellos, **tócalo**: se escribe solo y se llenan marca, modelo y teléfono.
- Si no es ninguno, **sigue capturando**: al guardar se registra como nuevo y queda **pendiente de verificar**.
- La tecla **Esc** cierra la lista.
