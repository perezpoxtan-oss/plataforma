# Bitácora de accesos (Control de Accesos)

Operación → **Accesos**. Es la pantalla de la caseta: aquí se registra a **todas las personas que entran** a la sede (colaboradores, huéspedes, visitas, proveedores, contratistas y servicios de emergencia), se ve **quién sigue dentro** y se registra su **salida**.

Está pensada para trabajar rápido en la PC o en el celular: botones grandes, poco que escribir y el **lector universal** (pistola, cámara o NFC) para gafetes, colaboradores y placas.

## 1. La pantalla

Arriba está el botón rojo **Nuevo Ingreso**, la búsqueda y los filtros por **tipo de persona** y **sede** (si solo tienes una sede, ya está elegida). Debajo hay tres pestañas:

| Pestaña | Qué muestra |
|---|---|
| **Pendientes de Autorización (n)** | Proveedores y contratistas que esperan que su Host los autorice |
| **Gente en Sitio (n)** | Todos los que están dentro ahora |
| **Historial Finalizados** | Los que ya salieron, por páginas, con filtro de fechas |

Cada tarjeta tiene el color del tipo de persona, el nombre, la sede, el **gafete** que se le prestó (o **S/G** si no lleva) y sus datos: motivo, ID que dejó en caseta, cómo llegó, vehículo, zona de estacionamiento y acompañantes. Abajo dice a qué hora entró y **quién lo registró**.

![Gente en sitio](img/accesos/01-gente-en-sitio.png)

## 2. Registrar un ingreso

1. Toca **Nuevo Ingreso**. Se abre el **Registro Inteligente de Ingreso**.
2. Revisa la **Sede** (si tienes una sola, ya viene puesta).
3. Elige el **Tipo de Persona**. El formulario cambia y solo pide lo que hace falta para ese tipo.
4. Llena los datos (ver abajo) y toca **Autorizar Ingreso y Guardar Datos**.
5. Si viene más gente detrás, usa **Guardar y capturar siguiente**: guarda y vuelve a abrir el formulario con la misma sede y el mismo tipo.

Si falta algo o algo está mal (por ejemplo un gafete que ya está prestado), el aviso en rojo aparece **dentro de la misma ventana** y no se pierde lo capturado.

### Colaborador

Escanea su credencial o escribe su nombre o número de empleado y elígelo de la lista. **No lleva gafete.**

Si no aparece (por ejemplo, es de nuevo ingreso), toca **«¿No aparece? Darlo de alta provisional»**: se registra como provisional y Recursos Humanos lo valida después.

![Ingreso de colaborador](img/accesos/04-ingreso-colaborador.png)
![Sugerencias al escribir](img/accesos/05-sugerencias-colaborador.png)

### Personal Externo / Visita

- **Tipo de Gafete** y **Gafete Asignado**: escanéalo o escribe y elige de la lista. Solo salen gafetes **libres** de la sede. Sin gafete queda «S/G».
- **Nombre**: escribe y elige de la lista del Padrón de personas, o escribe uno nuevo.
- **ID Custodiada**: la identificación que deja en caseta (INE, licencia o pasaporte).
- **Motivo de la Visita**: *Recursos Humanos* o *Visita a Colaborador* (en ese caso elige **a quién visita**).

![Visitante con gafete](img/accesos/06-ingreso-visitante-gafetes.png)
![Visitante del Padrón de personas](img/accesos/07-visitante-padron-personas.png)

Si escribes a mano un nombre que ya existe en el Padrón, el sistema pregunta **«¿Es la misma persona?»**: toca **Sí, es la misma** para usar su registro, o **No, es alguien distinto** para crear otra persona.

![¿Es la misma persona?](img/accesos/13-misma-persona.png)

### Cómo llegó y acompañantes (para casi todos los tipos)

- **Forma de Llegada**: *A Pie* o *En Vehículo*. Con vehículo escribe o escanea las **placas**: si el vehículo ya está en el Padrón Vehicular se usa ese; si no, se registra solo.
- **Enviar a**: zona de estacionamiento de la sede. Junto a cada zona se ve cuántos lugares están ocupados; si dice **LLENO** se puede asignar igual.
- **Acompañantes**: agrega una fila por persona que llega con él. Cada una puede llevar su propio gafete.

![Vehículo y acompañantes](img/accesos/08-visitante-vehiculo-acompanantes.png)

### Proveedor y Contratista (Obra)

Además del gafete, nombre y llegada:

- **Empresa / Procedencia**: elige de Proveedores o escribe una nueva. Una empresa **dada de baja (vetada) no puede entrar**.
- **Host — ¿Quién lo citó?**: el colaborador que lo espera. **Es obligatorio.**
- En **Más detalles**: Tipo de Visita, Departamento, Área de Trabajo y Actividad.

El registro queda **Pendiente**: aparece en la pestaña *Pendientes de Autorización* hasta que alguien con permiso toque **Confirmar Autorización**.

![Ingreso de proveedor](img/accesos/09-ingreso-proveedor.png)
![Más detalles del proveedor](img/accesos/10-proveedor-mas-detalles.png)
![Pendientes de autorización](img/accesos/02-pendientes.png)

### Huésped

**Nombre**, **Número de Habitación**, **¿Tiene Reserva?** (con reserva el pase siempre es **Estancia**; sin reserva elige *Daypass* o *Nightpass*) y **Agencia Vinculada** si viene por una agencia. Si llegó en taxi o app, escribe el **Conductor**. **No lleva gafete.**

![Ingreso de huésped](img/accesos/11-ingreso-huesped.png)

### Servicio de Emergencia

Lo mínimo para no hacer esperar: nombre o unidad (opcional; si no, queda «UNIDAD DE EMERGENCIA»), tipo de emergencia y observaciones. **No lleva gafete.**

![Ingreso de emergencia](img/accesos/12-ingreso-emergencia.png)

## 3. Dar salida

Hay dos formas:

- En la tarjeta de la persona toca **Registrar Salida** (en huéspedes, **Salida Final**) y confirma.
- Con **Dar Salida** (dentro del Registro de Ingreso): escribe placas, nombre, gafete o habitación, **o escanea el gafete que te devuelven**. La tarjeta te recuerda qué pedir de vuelta (gafetes e identificación).

Al salir el titular, sus acompañantes que seguían dentro salen con él y su lugar de estacionamiento queda libre. Sus gafetes vuelven a estar disponibles.

![Buscar y dar salida](img/accesos/14-dar-salida.png)

Un **acompañante** puede salir antes con el botón **Salida** de su renglón. En proveedores y contratistas también puede hacer **Temporal** (sale un rato, por ejemplo por material) y luego **Regresó**; mientras está fuera aparece la marca **FUERA** y su gafete sigue reservado.

## 4. Cambiar zona

En una persona con vehículo, **Cambiar Zona** la manda a otra zona de estacionamiento de la sede, o la libera con «Sin asignar (liberar)».

![Cambiar zona](img/accesos/15-cambiar-zona.png)

## 5. Salida a tour y regreso (huéspedes) · Salida temporal (proveedores)

- **Salida a Tour**: el huésped sale un rato. Puedes anotar placas, marca y conductor del tour. Su tarjeta cambia a **FUERA EN TOUR** (no se cuenta dos veces).
- **Registrar Regreso**: cuando vuelve. El vehículo y chofer de regreso pueden ser distintos.
- En proveedores y contratistas autorizados el botón se llama **Salida Temporal** y funciona igual.

![Salida a tour](img/accesos/16-salida-a-tour.png)
![Regreso de tour](img/accesos/17-regreso-de-tour.png)

## 6. Historial y exportar

En **Historial Finalizados** filtra por texto, tipo, sede y fechas (**Desde / Hasta**) y toca **Filtrar**. Con permiso de exportar aparece **Exportar a Excel**, que descarga exactamente lo filtrado.

![Historial](img/accesos/03-historial.png)

La ocupación de las zonas de **Estacionamientos** sale de esta bitácora: cuenta los vehículos que siguen en sitio.

![Ocupación de estacionamientos](img/accesos/18-ocupacion-estacionamientos.png)

## 7. Qué puede hacer cada rol

| Rol | Ve | Registra ingresos y salidas | Autoriza proveedores | Exporta |
|---|---|---|---|---|
| Administrador | Toda la empresa | Sí | Sí | Sí |
| Jefe de seguridad / Supervisor | Sus sedes | Sí | Sí | Sí |
| Asistente | Sus sedes | Sí | No | Sí |
| Agente | Su sede | Sí | No | No |
| Director | Toda la empresa | No | Sí | Sí |

El **Agente** ve los pendientes con el aviso «Espera a que el Host autorice; tu supervisor confirma la autorización en el sistema».

![Vista del agente](img/accesos/26-agente-pendientes.png)

## 8. Celular, modo Noche y modo Sol

Todo funciona en el celular: el botón **Nuevo Ingreso** ocupa todo el ancho y los botones del formulario quedan siempre a la mano abajo. Con el botón del sol (arriba a la derecha) cambias a **Noche** (pantalla oscura para el turno nocturno) o **Sol** (alto contraste para la caseta a pleno sol).

![Celular](img/accesos/23-celular-en-sitio.png)
![Ingreso en celular](img/accesos/24-celular-ingreso.png)
![Modo noche](img/accesos/19-noche.png)
![Modo sol](img/accesos/21-sol.png)

## Preguntas frecuentes

- **El gafete que escaneé dice que no está disponible.** Lo tiene alguien que sigue dentro o pendiente de autorizar, o está dado de baja. Usa otro o da la salida a quien lo tiene.
- **¿Por qué el proveedor no aparece en Gente en Sitio?** Está en *Pendientes de Autorización* hasta que lo autoricen.
- **Me equivoqué de zona.** Usa **Cambiar Zona**.
- **El colaborador no está en la lista.** Usa el alta provisional; RH lo valida después.

## Registro rápido: los avisos informativos se quedan (Ronda 5)

Al cerrar y volver a abrir **Registro Rápido de Persona**, el aviso gris «Úsalo solo si la persona **no aparece** al buscarla…» y el aviso amarillo de «pendiente de verificar» siguen ahí. Al cerrar una ventana solo se borran los errores de validación (rojos) y los avisos de «Guardado».

![El aviso gris se conserva](img/ronda-5b/registro-rapido-aviso-se-conserva.png)

## Ronda 8: buscar por el nombre de un acompañante

Escribe el nombre de un acompañante (por ejemplo «sofia») en la búsqueda de **Gente en Sitio**, **Pendientes** o **Historial**, o en **Dar Salida**: aparece la tarjeta de la persona a la que acompaña con el aviso «Coincide con SOFÍA MÉNDEZ, acompañante de LAURA MÉNDEZ RÍOS». No importan los acentos ni las mayúsculas.

![Búsqueda por acompañante](img/ronda-8/07-accesos-busqueda-acompanante.png)
![Dar Salida encuentra al acompañante](img/ronda-8/08-dar-salida-acompanante.png)
![Modo Noche](img/ronda-8/21-noche-accesos-acompanante.png)
