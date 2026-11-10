# ADR-0006 — Altas pendientes de verificar

**Fecha:** 2026-10-06 · **Estado:** aceptada

## Contexto

El responsable del proyecto pidió (observación J-04):

> «Padrones no puede editar ni cambiar nada (agente); desde Operación, si un auto, un colaborador, proveedor o algo que no exista en un padrón, debe dar sugerencia de que puede existir y, si no existe, debe haber un alta pendiente de verificar para aceptar o rechazar, pero de entrada que pueda usarse para el registro.»

Colaboradores ya lo tenía: la caseta da **altas provisionales** y Recursos Humanos las valida o las une con el registro correcto (ADR-0004, `AdministradorColaboradores::crearProvisional`).

Los demás padrones crecían desde Operación **sin ningún control**:

- La **Bitácora de accesos** creaba vehículos (por placas), personas y empresas externas al registrar un ingreso.
- La **Bitácora de transporte** creaba unidades y choferes.
- **Pases de salida** y **Lost & Found** tenían altas rápidas («Nueva Empresa Externa», «Registro Rápido de Persona»), pero solo para quien tenía permiso de alta en el padrón. El Agente no las veía.

Así se llenaban los padrones de duplicados con otra escritura («ABC-123-A» y «ABC123A», «Laura Méndez Ríos» y «LAURA MENDEZ RIOS», «Abarrotes del Caribe» y «Abarrotes del Caribe S.A. de C.V.»). Nadie se enteraba.

## Decisión

1. **Se generaliza el patrón de Colaboradores a Vehículos, Empresas externas (proveedores) y Personas.** El servicio común es `App\Services\Padrones\AltasPorVerificar`. Colaboradores conserva su flujo propio con Recursos Humanos.
2. **Primero se sugiere, después se crea.** Antes de crear, la caseta ve «¿Es alguno de estos?» con los registros parecidos:
   - sin distinguir mayúsculas, acentos, espacios, guiones ni signos;
   - las placas sin separadores, con O/0 e I/1 como iguales;
   - las razones sociales sin «S.A. de C.V.» y similares;
   - las mismas palabras en otro orden, o un apellido de más;
   - con distancia de Levenshtein o `similar_text`, sobre un grupo acotado de candidatos (máximo 300 por consulta);
   - siempre dentro de la empresa; en empresas externas, también dentro de las sedes donde opera quien pregunta.

   Los dados de baja salen marcados y no se pueden elegir. Los rechazados y los ya unidos no salen.
3. **Si no es ninguno, se crea y se usa de inmediato**, con `verificacion = pendiente`. Quien lo registra no espera a nadie.
4. **Quien puede editar el padrón** (`vehiculos.editar`, `proveedores.editar`, `visitantes.editar`) **lo verifica** desde la lista del padrón. Tiene tres caminos:
   - **Aceptar**: si hace falta, corrige los datos con las reglas normales del padrón.
   - **Rechazar** con un motivo: el registro se queda como historia, pero desactivado y sin poder usarse.
   - **Unir con el existente**: todo lo registrado con el alta pasa al registro correcto (`AltasPorVerificar::REFERENCIAS`), y el alta queda «Unida» apuntando a él (`fusionado_en_id`).

   Si quien registra ya puede editar ese padrón, el registro **nace verificado**, porque sería verificarse a sí mismo.
5. **Permisos.**
   - El Agente sigue con los Padrones en **solo consulta**.
   - Las altas desde Operación usan el **permiso operativo** de la pantalla de origen:
     - `accesos.crear` (vehículos, personas y empresas);
     - `transporte.crear` (vehículos y personas);
     - `pases_salida.crear` (empresas);
     - `lost_found.firmar` (personas).
   - Los registros rápidos (`/vehiculos/rapido`, `/personas/rapido`, `/proveedores/rapido`) aceptan el permiso del padrón o el operativo (`origen`).
   - Con el permiso operativo solo se crean altas **pendientes**. Una empresa externa que ya existe se usa tal cual: no se tocan sus sedes.
6. **Avisos.**
   - Aviso único en Inicio, «Altas por verificar», agrupado por padrón. Solo muestra los padrones que el usuario edita, dentro de su alcance de sede.
   - Píldora «Pendientes de verificar» en cada padrón.
   - Correo a quien puede verificar en esa sede. Se apaga en Configuración → Avisos por correo, clave `alta_por_verificar` de `Empresa::AVISOS`.
7. **Lo rechazado no se usa más.**
   - La Bitácora de accesos, la de transporte y los registros rápidos responden: «… fue rechazado al verificar el padrón (motivo: …)».
   - Un alta unida se sustituye sola por el registro correcto: las mismas placas o el mismo nombre llevan al correcto.
   - Un registro rechazado no se reactiva ni se edita.

## Consecuencias

- Todo módulo nuevo que guarde `vehiculo_id`, `proveedor_id` o `persona_id` (o su equivalente, como `chofer_id`) se registra en `AltasPorVerificar::REFERENCIAS`. La prueba `AltasPorVerificarTest::test_toda_columna_que_apunta_a_vehiculos_proveedores_o_personas_esta_en_referencias` falla si falta una.
- Toda pantalla de Operación nueva que cree algo en estos padrones debe:
  - llamar `registrarAlta()` después de crearlo;
  - pasar lo que reutiliza por `paraOperacion()`;
  - agregarse a `ORIGENES` / `ORIGENES_POR_PADRON`.
- Migración `2026_10_12_000300_altas_pendientes_de_verificar`. Agrega a `vehiculos`, `proveedores` y `personas`:
  - `verificacion`, `verificado_por`, `verificado_en`, `motivo_rechazo`, `fusionado_en_id`, `origen_alta`, `sede_alta_id`;
  - lo existente queda como «verificado».
- Las pruebas que decían «el Agente no usa el registro rápido» cambian: ahora sí lo usa, pero su alta queda pendiente.
