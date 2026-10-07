# Bitácora de Novedades (Despacho y Novedades)

Operación → **Bitácora de novedades**. Aquí se levanta un **ticket** por cada cosa que pasa en la sede (un accidente, un objeto olvidado, un robo, una habitación abierta con valores, un conato de incendio…) y se le da seguimiento hasta cerrarlo.

La idea es la misma que en SEGCAT:

1. Alguien reporta algo → la caseta **despacha un ticket rápido** (unos cuantos datos).
2. Quien atiende abre el **Expediente** y llena el **formato de la categoría** (por ejemplo, el de Accidente).
3. Cada avance se anota en el **Minuto a Minuto**.
4. Cuando todo está documentado, el caso se marca **Resuelto** (con su resolución).

## 1. La pantalla

Arriba está el buscador (por número de ticket, ubicación o lo que se reportó), el filtro de **categoría** y, si tienes varias sedes, el de **sede**.

Hay dos pestañas:

- **Tickets Abiertos / Asignados**: fichas con borde **rojo**. Las que dicen **PENDIENTE DE TURNO** las debe atender el siguiente turno.
- **Historial Resueltos**: fichas con borde **verde**.

Cada ficha muestra la categoría, el número (`#00012`), la sede, la ubicación, lo que pasó, quién lo creó y quién le dio seguimiento. Toca **Abrir Expediente** para atenderlo (o **Ver Expediente** si solo puedes consultarlo).

![Lista de tickets](img/novedades/01-lista.png)

![Historial de resueltos](img/novedades/02-historial-resueltos.png)

> **Exportar** descarga en Excel (CSV) exactamente lo que estás viendo con tus filtros. Solo aparece si tienes ese permiso.

## 2. Despachar un ticket rápido

Toca **Nuevo Ticket**. Se abre **Generar Ticket Rápido**:

- **Sede**: si solo tienes una, ya viene elegida.
- **Categoría**: si todavía no sabes qué es, deja *Sin clasificar todavía*; se confirma al atender.
- **¿Quién reporta?**: escribe el nombre (te sugiere colaboradores), toca **Fui yo quien lo observó**, o **escanea el gafete** de quien reporta (lector, cámara o NFC).
- **¿A quién se canaliza?** (opcional): la persona que lo va a atender.
- **Área General**: edificio y piso.
- **Ubicación específica**: por ejemplo «Piso 2, cerca del elevador».
- **¿Cuándo sucedió?**: ya trae la hora actual; cámbiala si el hecho fue antes.
- **¿Qué sucedió?** y, si ya se sabe, **¿Cómo sucedió?**

Toca **Despachar Ticket**.

![Generar Ticket Rápido](img/novedades/03-generar-ticket-rapido.png)

Si falta algo, el aviso aparece en rojo **dentro** del mismo cuadro y lo que ya escribiste se conserva:

![Avisos dentro del diálogo](img/novedades/04-alta-con-errores.png)

## 3. Atender: el Expediente de Novedad

Arriba ves el **reporte inicial** (no cambia). Debajo, las **Preguntas Base**, que puedes confirmar o completar: categoría, a quién se canaliza, edificio y piso, **habitación específica** (si fue en una habitación), involucrados, cuándo y cómo sucedió.

Al elegir la categoría aparece su **formato**:

| Categoría | Qué se captura |
|---|---|
| Reporte General | Quién fue observado, qué hacía, por qué, acciones inmediatas de seguridad |
| Accidente / Lesión | Formato de huésped o de colaborador, dictamen médico con el mapa del cuerpo, anexo de guardavidas, RH y **6 firmas** |
| Valores a la Vista | Habitación, personal involucrado, puertas/ventanas/terrazas, caja fuerte y valores por zona |
| Siniestro Protección Civil | Tipo de evento, alarma, evacuación, servicios externos, lesionados, equipos, daños, testigos, causa |
| Lost & Found | Artículos encontrados (folio LF-…) y reportes de pérdida (folio RP-…) |
| Robo | Circunstancias, qué se llevaron, sospechoso, testigos con su declaración, policía y canalización |

![Expediente: Reporte General](img/novedades/05-expediente-reporte-general.png)

### Minuto a Minuto

En el recuadro oscuro se ve todo lo que ha pasado con el caso, con fecha, hora y nombre. Para agregar algo, escríbelo en la línea de abajo y toca **Guardar Expediente**. Lo escrito **no se puede borrar ni cambiar** (así queda constancia).

### Estatus

- **Abierto / Seguimiento Pendiente**.
- **Pendiente de Turno**: lo atiende el siguiente turno.
- **Resuelto y Cerrado**: primero escribe en **Estatus Final / Resolución** cómo se concluyó; si no, el sistema no te deja cerrarlo.

Toca **Guardar Expediente**. El imprimible está en el botón de la impresora (arriba a la derecha).

## 4. Accidente / Lesión

Es **el mismo formulario de SEGCAT**, con las mismas secciones:

1. **Seguridad: Formato de Afectado** — elige *Huésped / Cliente* o *Colaborador Interno*. Para un colaborador, **escanea su gafete** o escribe su número de empleado: el departamento y el puesto se llenan solos.
2. **Servicio Médico: Dictamen Clínico** — marca el tipo de herida y toca en el **mapa del cuerpo** las zonas afectadas (se ponen en rojo).
3. **Guardavidas: Anexo Acuático** — solo si aplica.
4. **Uso Exclusivo Recursos Humanos** — días de incapacidad (solo colaboradores).
5. **Firmas Digitales de Cierre** — elige quién firma, que firme con el dedo y toca **Guardar Esta Firma**. Repite con cada persona (afectado, seguridad, médico, jefe, RH, ejecutivo). Las firmas se guardan al tocar **Guardar Expediente**.

![Expediente de Accidente (huésped)](img/novedades/06-expediente-accidente-huesped.png)

![Accidente de colaborador, ya resuelto](img/novedades/07-expediente-accidente-colaborador-resuelto.png)

![Impresión del expediente con firmas](img/novedades/15-imprimir-accidente.png)

## 5. Valores a la Vista

Para cuando se encuentra una habitación abierta, con la caja fuerte abierta o con valores a la vista. Agrega con los botones cada **persona**, **puerta / ventana / terraza** y los **valores por zona** (Recámara, Sala, Baño…).

![Valores a la Vista](img/novedades/08-expediente-valores-vista.png)

## 6. Siniestro de Protección Civil

Si marcas que **hubo lesionados** y cuántos, al guardar se abre **solo** un ticket de **Accidente** ligado, para documentar a cada lesionado (te avisa en el Minuto a Minuto).

![Siniestro de Protección Civil](img/novedades/09-expediente-siniestro-pc.png)

## 7. Lost & Found

- **Artículos Encontrados**: cada uno recibe su folio **LF-000123** al guardar y queda **En Resguardo**. El semáforo dice si está *En tiempo*, *Por vencer* o *Vencido* según los días de resguardo de su tipo.
- **Reportes de Pérdida**: lo que un huésped dice que perdió (folio **RP-000123**). Toca **Buscar Coincidencias** para ver artículos parecidos ya encontrados y, si es el suyo, **Vincular**.
- **Imprimir Acuse de Recibo**: comprobante para quien entregó lo encontrado.

![Lost & Found](img/novedades/10-expediente-lost-found.png)

![Buscar Coincidencias](img/novedades/18-buscar-coincidencias.png)

![Acuse de recibo](img/novedades/16-acuse-lost-found.png)

> Quien solo tiene permiso de **Lost & Found** (por ejemplo, Ama de Llaves) ve y trabaja únicamente los tickets de Lost & Found.

## 8. Robo

Captura lo más preciso posible. Antes de darlo por robo, toca **Buscar Coincidencias en Lost & Found**: a veces solo se extravió. Si el ticket tiene **habitación específica**, la **Ficha de Hechos** muestra todo lo ocurrido en esa habitación y en su zona de 7 días antes a 30 días después.

![Robo](img/novedades/11-expediente-robo.png)

![Ficha de Hechos](img/novedades/17-ficha-de-hechos.png)

## 9. Reabrir un caso resuelto

Un caso Resuelto se ve en solo lectura. Si hay que corregirlo, toca **Reabrir Caso para Editar** y explica el motivo: queda en el Minuto a Minuto.

![Justificar reapertura](img/novedades/14-justificar-reapertura.png)

## 10. Otros casos

- **Sin clasificar**: elige la categoría para que aparezca su formato.
- **Recorrido Protección Civil**: solo en tickets antiguos; los recorridos nuevos se hacen en su propio módulo.

![Sin clasificar](img/novedades/13-expediente-sin-clasificar.png)

![Recorrido PC histórico](img/novedades/12-expediente-recorrido-pc.png)

## 11. En el celular, de noche y a pleno sol

Todo funciona en el celular: las fichas se acomodan en una columna y los botones son grandes.

![Celular: lista](img/novedades/20-movil-lista.png) ![Celular: alta](img/novedades/21-movil-alta.png) ![Celular: mapa del cuerpo](img/novedades/23-movil-mapa-corporal.png)

Con el botón del sol (junto a tu nombre) cambias a **Sol** (alto contraste) o **Noche**:

![Modo Noche](img/novedades/41-noche-expediente-accidente.png)

![Modo Sol](img/novedades/40-sol-lista.png)

## 12. ¿Qué puede hacer cada quien?

| Rol | Puede |
|---|---|
| Agente | Ver, despachar, atender e imprimir los tickets de su sede. No reabre ni exporta. |
| Supervisor / Jefe de seguridad | Todo en su sede, incluido reabrir y exportar. |
| Administrador | Todo en la empresa. |
| Ama de Llaves (con permiso de Lost & Found) | Solo tickets de Lost & Found. |

![Vista del agente](img/novedades/30-agente-lista.png)

## Ronda 8: lo que cambió

### Firmas del Accidente según quién se accidentó

En **Firmas Digitales de Cierre**, la lista «Seleccione quién va a firmar en este momento» cambia según el **Tipo de Afectado** que elegiste arriba:

- **Huésped / Cliente**: Huésped / Afectado, Testigo, Agente de Seguridad (Atiende), Supervisor de Seguridad, Médico / Enfermería y Gerente en Turno / Ejecutivo de Guardia.
- **Colaborador Interno**: Colaborador Afectado, Jefe Inmediato, Testigo, Agente de Seguridad (Atiende), Supervisor de Seguridad, Servicio Médico y Recursos Humanos.

Pasos: elige quién firma → que firme en el recuadro → **Guardar Esta Firma** → repite con el siguiente → **Guardar Expediente**. Si se te olvida tocar «Guardar Esta Firma», la firma que quedó en el recuadro se guarda igual para la persona elegida.

![Firmantes de un huésped](img/ronda-8/01-firmantes-huesped.png)
![Firmantes de un colaborador](img/ronda-8/02-firmantes-colaborador.png)

Si algún día el navegador muestra un error al guardar, ahora verás la página de la plataforma en español («La acción no llegó completa») con un botón para regresar; vuelve a abrir el expediente y revisa si tus cambios ya están.

![Página de error 405](img/ronda-8/23-error-405.png)

### Nuevo Ticket: clasificación obligatoria y canalizar solo a Seguridad

- **Categoría** ya no viene en «Sin clasificar»: elige la más cercana (se puede corregir al atender). Si no la eliges, la ventana te lo pide.
- **¿A quién se canaliza?** muestra solo al **personal de Seguridad** de esa sede (agentes, supervisores, jefes y mandos), ya no a todo el personal.

![Nuevo ticket sin clasificación](img/ronda-8/03-nuevo-ticket-sin-clasificacion.png)

### Apartados que se contraen

En los formatos largos (Valores a la Vista, Siniestro, Lost & Found y Robo) cada apartado numerado se abre o se cierra tocando su título (flecha a la derecha). El primero viene abierto; la plataforma recuerda cuáles dejaste abiertos. Si un apartado tiene un error, se abre solo y dice **Revisar**. El formato de **Accidente** se queda igual que siempre.

![Apartados que se contraen en Robo](img/ronda-8/04-secciones-plegables-robo.png)

En el celular, de noche y a pleno sol:

![Celular](img/ronda-8/16-celular-secciones-robo.png)
![Noche](img/ronda-8/20-noche-secciones.png)
![Sol](img/ronda-8/18-sol-secciones.png)
