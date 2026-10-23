---
titulo: Padrón vehicular
modulos: [vehiculos]
seccion: Padrones
orden: 30
resumen: Registrar los vehículos que entran a la empresa e imprimir su calcomanía con código QR.
---

# Padrón vehicular

**¿Para qué sirve?** Aquí están todos los vehículos que entran a la empresa. Solo hay **dos categorías**: **Particular** (el auto de un colaborador, huésped, visitante o familiar) y **De una empresa** (flotillas, autos de agencia, taxis y transporte de personal). Cada vehículo tiene una **calcomanía con código QR** que la caseta puede leer con el lector.

![Padrón vehicular](img/vehiculos/v1-lista.png)

## Antes de empezar

- Para ver la pantalla tu rol debe poder consultar el *Padrón vehicular*. Si no aparece en el menú, pide el permiso a tu administrador.
- Para registrar, editar, dar de baja o imprimir calcomanías necesitas esos permisos. Si no ves **Registrar Vehículo** ni el lápiz, tu rol solo consulta (como el agente de caseta).
- Si tu rol solo puede editar «lo propio», solo podrás editar o dar de baja los vehículos que tú registraste.

## Cómo encontrar un vehículo

1. Entra a **Padrones → Padrón vehicular**.
2. Escribe en **Buscar por placas, marca, color o proveedor...** las placas (con o sin guiones: `ABC-123-A` o `abc123a`), la marca, el color, el número económico, la empresa o el colaborador.
3. Usa los botones de **Categoría**: **Todos**, **Particulares** o **De una empresa**. El número junto a cada uno dice cuántos hay.
   - **Particulares:** el auto de un colaborador, huésped, visitante o familiar.
   - **De una empresa:** flotillas, autos de agencia, taxis y transporte de personal.
4. Si quieres, elige también un **Tipo / Estilo** (Sedán, SUV, Pick-up…). Por ejemplo, **De una empresa** + **Autobús** te deja solo el transporte de personal.

**Qué debes ver:** solo los vehículos que coinciden. La búsqueda y el filtro se recuerdan mientras la pestaña siga abierta.

## Cómo registrar un vehículo

1. Toca la tarjeta **Registrar Vehículo**. Se abre **Alta de Vehículo**.
2. Escribe las **Placas (Únicas)** como vengan. Se guardan en mayúsculas y sin espacios ni guiones; debajo verás cómo quedarán («Se guardarán como UVW482B.»).
3. En **¿De quién es?** (la categoría) elige una de las dos:
   - **Particular (de un colaborador, huésped, visitante, familiar…)**: no pide empresa. Si es el auto de un colaborador, elígelo en **Colaborador (dueño del vehículo)**; es opcional.
   - **De una empresa (flotilla, agencia, taxi, transporte…)**: pide la **Empresa propietaria** (elígela de la lista) y el **Número Económico**, el número pintado en la unidad (por ejemplo `T-045`). Los dos son obligatorios.
4. En **¿Qué forma tiene?** (el tipo) elige: Sedán, SUV / Camioneta, Pick-up, Autobús, Camión ligero, Motocicleta u Otro.
   - Con **Otro** escribe en **Describe el Tipo de Vehículo** qué es (por ejemplo «Carrito de golf»).
   - En autobús, camión ligero o un vehículo de una empresa aparece **Capacidad Máxima** (de 1 a 99 personas, opcional).
5. Escribe la **Marca** y el **Color Físico** (obligatorios) y, si lo sabes, el **Modelo**.
6. Toca **Guardar**.

**Qué debes ver:** «Vehículo UVW482B registrado correctamente.» y, debajo, **Siguiente paso: Imprimir su calcomanía**. Tócalo y se abre la calcomanía en otra pestaña, lista para imprimir.

> **«¿De quién es?» y «¿Qué forma tiene?» no son lo mismo.** La *Categoría* dice **de quién es** (de una persona o de una empresa). El *Tipo / Estilo* dice **qué forma tiene** (sedán, pick-up…). Por eso «Otro» está en Tipo: un carrito de golf del hotel es Tipo «Otro».

> **¿Un taxi o un auto rentado?** Son **De una empresa**: elige la empresa de taxis o la agencia y escribe su número económico. Si la empresa todavía no existe, dala de alta primero en [Empresas externas](proveedores.md).

### Avisos mientras escribes las placas

- **Verde:** «Se guardarán como …». Puedes seguir.
- **Rojo:** «Las placas … ya están registradas en el padrón. No se pueden repetir.» Busca ese vehículo en la lista.
- **Rojo con botón Reactivar:** las placas son de un vehículo dado de baja. Toca **Reactivar** en lugar de registrarlo otra vez.
- **Amarillo:** «Se parecen a placas ya registradas (la O y el 0, o la I y el 1, se confunden). Revisa que no sea el mismo vehículo:».

![Placas repetidas](img/vehiculos/v13-placas-repetidas.png)

### Registrar un vehículo de una empresa externa

En **Padrones → Empresas externas → Ficha → Flotilla** toca **Agregar vehículo**. La ventana dice «Registrando unidad de …», la categoría ya dice **De una empresa** y la empresa ya viene puesta, con candado: solo falta el número económico y los datos del vehículo. Al guardar o al cerrar regresas a la ficha de la empresa, en la pestaña **Flotilla**.

## Cómo cargar muchos vehículos desde Excel

1. Toca **Importar** (arriba, junto al buscador).
2. Toca **Descargar plantilla** y llénala: **Placas**, **Categoría** («Particular» o «De una empresa»), **Tipo**, **Marca** y **Color** son obligatorios. Los de una empresa llevan **Número económico** y **Empresa propietaria** (que ya debe estar en Empresas externas).
3. Toca **Elegir archivo**, elige tu archivo, toca **Revisar archivo**, revisa la vista previa y toca **Guardar**.

**Qué debes ver:** el resultado de cada fila. Si dos filas traen las mismas placas, la segunda sale «Repetido en el archivo»; si las placas ya están en el padrón, sale **Ya existe**. Ver [Importar desde Excel](importar-desde-excel.md).

## Cómo editar un vehículo

1. En la tarjeta toca el **lápiz** (Editar).
2. Corrige los datos en **Actualizar Vehículo**.
3. Toca **Guardar**.

![Editar un vehículo](img/vehiculos/v6-editar.png)

**Qué debes ver:** «Vehículo … actualizado correctamente.» Si cambias la categoría, los datos que ya no aplican (número económico, empresa, colaborador, capacidad) se borran al guardar.

## Cómo imprimir la calcomanía y asignar una etiqueta NFC

1. Para imprimir, toca la **impresora** de la tarjeta (o, en **Código e identificación**, **Imprimir calcomanía**): se abre en otra pestaña. Ahí toca **Imprimir Calcomanía** (los botones no salen en la hoja). **Volver al Padrón Vehicular** te regresa.
   - Antes de imprimir lee el recuadro amarillo: en la ventana de la impresora elige **«Tamaño real»** o **«Escala 100 %»** (no «Ajustar a la página»). Si no, la calcomanía sale más chica.
   - Si necesitas otra medida, toca **Imprimir con una plantilla de Etiquetas QR**. Si tu empresa eligió imprimir siempre con plantillas, el botón te lleva directo a la hoja de [Etiquetas QR](etiquetas-qr.md) y la impresión queda en su historial.
2. El botón del **código QR** abre **Código e identificación** con el QR y la dirección del vehículo (con botón **Copiar**).
3. Para ligar un tag NFC o RFID: en **Asignar etiqueta NFC / RFID** acerca el tag al lector (o escribe su número) y toca **Guardar etiqueta**. Para quitarlo, toca **Quitar etiqueta**.

![Código e identificación](img/vehiculos/v8-identificacion.png)

![Calcomanía](img/vehiculos/v7-calcomania.png)

**Qué debes ver:** «Etiqueta … asignada. Ya se puede leer con el lector.» Al leer el QR con un celular con sesión iniciada se abre la ficha del vehículo. El QR no contiene datos personales.

## Cómo dar de baja o reactivar un vehículo

1. Toca el botón rojo **Dar de baja** (círculo con raya) y confirma con **Aceptar** («¿Dar de baja este vehículo? Podrás reactivarlo con un clic.»).
2. Para regresarlo toca **Reactivar** (flecha circular) y confirma.

**Qué debes ver:** la tarjeta marcada **DE BAJA**. Nunca se borra: las bitácoras dependen del vehículo.

## Lo que ve el agente de caseta

El agente consulta y busca vehículos, pero no ve **Registrar Vehículo** ni los botones de editar o dar de baja.

![Vista del agente](img/vehiculos/v15-agente.png)

## En el celular y en modo Noche

![Celular](img/vehiculos/v12-celular.png) ![Modo Noche](img/vehiculos/v9-noche.png)

## Si algo sale mal

Los mensajes salen en rojo dentro de la ventana; lo que escribiste se conserva.

![Error dentro de la ventana](img/vehiculos/v14-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| Las placas son obligatorias. | El campo está vacío. | Escribe las placas. |
| Revisa las placas: son muy cortas. / son muy largas (máximo 20 caracteres). | Faltan o sobran letras. | Revisa la placa física. |
| Las placas solo llevan letras y números (los espacios y guiones se quitan solos). | Escribiste signos como `#` o `/`. | Escribe solo letras y números. |
| Las placas «…» ya están registradas en esta empresa. | Ese vehículo ya existe. | Búscalo; si está dado de baja, reactívalo. |
| Un vehículo de una empresa lleva su empresa propietaria: elígela de la lista. | Elegiste *De una empresa* y falta la empresa. | Elige la empresa en la lista; si no está, dala de alta en Empresas externas. |
| Un vehículo de una empresa lleva su número económico (el que trae pintado la unidad). | Elegiste *De una empresa* y falta el número económico. | Escribe el número pintado en la unidad. |
| Describe el tipo de vehículo (por ejemplo: montacargas, carrito de golf). | Elegiste «Otro» sin describirlo. | Escribe qué vehículo es. |
| La capacidad debe ser de 1 a 99 personas. | Número fuera de rango. | Corrige la capacidad. |
| La empresa propietaria no existe en esta empresa o está desactivada. | La empresa se dio de baja. | Elige otra o pide que la reactiven. |
| El colaborador no existe en esta empresa o está dado de baja. | El colaborador ya no está activo. | Elige otro colaborador. |
| No veo el botón **Registrar Vehículo**. | Tu rol solo consulta. | Pide el permiso a tu administrador. |

## Preguntas frecuentes

**¿Dónde quedaron «Propio Huésped», «Taxi / App» y las demás categorías?** Se juntaron en dos: las cuatro «Propio…» ahora son **Particular**, y Agencia, Flotilla, Taxi y Transporte de personal ahora son **De una empresa**. Los vehículos que ya tenías se acomodaron solos.

**¿Tengo que escribir los guiones de las placas?** No. Escríbelas como quieras; se guardan sin espacios ni guiones.

**¿Por qué no puedo registrar unas placas?** Porque ya existen. Si están dadas de baja, usa **Reactivar**.

**¿Qué significa «Pendiente de verificar» en una tarjeta?** Que la caseta registró el vehículo al momento y falta que alguien lo revise. Ver [Altas por verificar](altas-por-verificar.md).

**¿Puedo reimprimir una calcomanía?** Sí, cuantas veces quieras desde **Código e identificación**. Para imprimir muchas a la vez usa [Etiquetas QR](etiquetas-qr.md).

## Relacionado

- [Empresas externas](proveedores.md)
- [Estacionamientos](estacionamientos.md)
- [Etiquetas QR](etiquetas-qr.md)
- [Altas por verificar](altas-por-verificar.md)
- [Importar desde Excel](importar-desde-excel.md)
