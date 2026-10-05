# Catálogo de Llaves

Seguridad → Padrones → **Catálogo de llaves**. Aquí está el inventario de todas las llaves de tus sedes: llaves metálicas, tarjetas electrónicas, accesos con huella y claves. Para cada llave se anota **qué abre**, **en qué horario** sirve y **hasta cuándo** es válida. Cada llave tiene una **etiqueta con código QR** para su llavero.

> El préstamo diario de llaves (quién se la llevó y cuándo la regresó) se registra en **[Préstamo de llaves](prestamo-llaves.md)**, no aquí. Mientras una llave está prestada, su ficha muestra **EN USO** y **Usada por**; el enlace **Historial de préstamos** abre sus movimientos.

![Lista del catálogo de llaves](img/llaves/01-lista.png)

## Cómo leer una ficha

- Arriba a la izquierda, el **tipo de dispositivo**; a la derecha, **ACTIVA** o **BAJA** y la casilla para marcarla a imprimir.
- El **nombre** de la llave (por ejemplo `HDC-P1-AMA`) y la descripción de lo que abre.
- Una vista previa de la etiqueta. El color de la barra indica el tipo: **verde** electrónica, **rojo** metálica, **morado** biométrica, **ámbar** clave.
- **Sede**, **Abre** (los lugares), departamento, ID externo y **Horarios** (si no tiene, dice "24 horas (todos)").
- **Caduca**: en verde si está vigente, en ámbar si **vence pronto** (30 días o menos) y en rojo si ya está **VENCIDA**.
- Abajo, el **Alcance** y los botones: dar de baja (círculo rojo), reactivar (flecha verde) y editar (lápiz).

## Buscar y filtrar

- Escribe en **Buscar llave o área...** el nombre, la descripción, un lugar (por ejemplo `101` o `Torre A`), el departamento o el responsable.
- Usa las listas **Sede**, **Tipo** y **Caducidad**, y los botones **Todas / Activas / Bajas**.
- Los filtros se recuerdan mientras la pestaña siga abierta.

## Registrar una llave

1. Toca **Nueva Llave**.
2. Elige la **Sede**. Departamento y Puesto objetivo son opcionales (el puesto se acota según el departamento).
3. **Responsable Permanente** solo en casos excepcionales: escanea el gafete del colaborador o escribe su número de empleado.
4. Escribe el **Nombre de la Llave**. Debajo verás si ya existe otra con ese nombre.
5. Escribe la **Descripción de Accesos** (qué abre).
6. Elige el **Tipo Dispositivo** y el **Alcance de Apertura**. Según el alcance aparece la lista de lugares de esa sede, tomada de **Zonas y áreas**:
   - **Global (Master Key)**: abre todo; no pide nada más.
   - **Edificio / Zona completa**, **Piso** o **Área específica / Cuarto**: marca uno o varios.
   - **Sección (grupo de habitaciones)**: marca las secciones.
   - **Otra**: escribe el lugar (por ejemplo "Cuarto de máquinas").
7. En llaves electrónicas, biométricas o de clave puedes anotar el **ID Externo** y la **Plataforma** que lo generó (VingCard, Salto…).
8. Opcional: **Fecha de Caducidad** y **Etiqueta NFC / RFID** (acerca la tarjeta o llavero al lector).
9. **Horarios de Apertura Válidos**: nombre, hora de inicio y fin. Usa **Agregar otro horario** o la **X** para quitar. Sin horarios, la llave vale las 24 horas.
10. Toca **Guardar e Indexar Llave**.

![Alta de llave](img/llaves/02-alta.png)
![Lugares, ID externo y horarios](img/llaves/02b-alta-horarios.png)

Si algo falta, la ventana se queda abierta y el aviso rojo dice qué corregir.

## Editar

Toca el lápiz. Si cambias la **sede**, vuelve a marcar los lugares: los de la otra sede ya no aplican.

![Editar una llave](img/llaves/03-edicion.png)
![Horarios al editar](img/llaves/03b-edicion-horarios.png)

## Dar de baja (con voucher) y reactivar

1. Toca el **círculo rojo** de la ficha.
2. Elige el **Motivo** (Extraviado, Dañado o Robado) y cuenta **¿Cómo pasó?**.
3. Si se le cobrará a alguien, marca **Aplica CXC**: aparece el **Monto** (sugerido con el último cobro de ese tipo de llave; puedes ajustarlo) y el **Colaborador responsable**.
4. Toca **Generar Voucher y Dar de Baja**. El aviso verde muestra el **folio** del voucher (`VR-…`).

![Dar de baja con voucher](img/llaves/04-baja.png)

Para reactivar una llave dada de baja toca la **flecha verde**. El voucher no se borra.

## Imprimir etiquetas

1. Marca la casilla de cada llave (o toca **Todo** para marcar las que se ven con el filtro actual).
2. Toca **Imprimir Etiquetas**: se abre una pestaña con las etiquetas de 6.5 × 3.5 cm listas para mica.
3. Toca **Imprimir Etiquetas** en esa pestaña.

![Etiquetas de llaveros](img/llaves/05-etiquetas.png)

Al escanear el QR con la cámara del celular se abre la ficha de la llave (con tu sesión iniciada).

## Exportar

**Exportar** descarga un archivo para Excel con las llaves que coinciden con los filtros que tengas puestos.

## En el celular y de noche

![En el celular](img/llaves/06-movil.png)
![Alta en el celular](img/llaves/06b-movil-alta.png)
![Modo Noche](img/llaves/07-noche.png)
![Modo Sol](img/llaves/08-sol.png)

## Quién puede hacer qué

| Rol | Puede |
|---|---|
| Administrador | Todo, en todas las sedes |
| Jefe de seguridad | Todo, en sus sedes |
| Asistente | Registrar, editar, imprimir y exportar en su sede |
| Supervisor | Igual que el Jefe, sin dar de baja |
| Agente | Solo consultar las llaves de su sede |

![Lo que ve un Agente](img/llaves/09-agente-movil.png)
