# Lección 19 — Préstamo de llaves y Responsivas

**Para:** Operador de caseta (Agente), Supervisor, Jefe de seguridad y Administrador · **Duración:** 25 minutos

## Lo que vas a aprender

- Prestar una llave escaneando la llave y el gafete, y capturar varias seguidas sin cerrar el cuadro.
- Recibir, anular y reactivar un préstamo.
- Entregar equipo con un resguardo firmado, imprimir la hoja y recibir el lote.

## Práctica (en QA)

### Préstamo de llaves

1. **`agente.demo`:** abre Operación → Préstamo de llaves.
   - En **Llaves en Uso** están `HDC-MASTER-01` (Roberto Hernández) y `HDC-BOD-01` (Guadalupe Chan). Solo ves las de **Hotel Demo Centro**: es tu sede.
   - Abre **Historial de Entregas**: `HDC-TA-ZONA` regresó y `HDC-P1-AMA` está **ANULADO**.
2. Toca el **reloj** de `HDC-MASTER-01`: revisa quién la entregó y que sigue **AÚN EN USO**.
3. Toca **Prestar Llave**. La sede ya viene elegida.
   - En la llave escribe `hdc-101` y Enter.
   - En el colaborador escribe `1007` y Enter (Mariana López).
   - Garantía **INE**, folio `ine-1234`. Toca **Registrar y Capturar Siguiente**.
   - Sin cerrar: llave `hdc-vista-mar`, colaborador `1009`, garantía **Ninguna (Riesgo)**. Registra. El contador dice 2.
   - Prueba la llave `hdc-101` otra vez: el sistema avisa que **ya está fuera**.
   - Prueba la llave `hdc-site-ti` con el colaborador `1008` (Daniela, de Playa): el aviso rojo sale dentro del cuadro.
4. Cierra el cuadro y toca **Recibir Llave a Caseta** en `HDC-101`. Ahora está en el historial con la hora de regreso.
5. Fíjate: como Agente **no** ves el botón de anular ni el **Excel (Auditoría)**.
6. **`jefe.demo`:** abre Préstamo de llaves. Ves las dos sedes.
   - Anula el préstamo de `HDC-VISTA-MAR` (botón gris ⊘): la llave queda libre.
   - En el historial, **Reactívalo**.
   - Descarga el **Excel (Auditoría)** y ábrelo.
7. **`admin.demo`:** abre Padrones → Catálogo de llaves: `HDC-MASTER-01` dice **EN USO** y **Usada por: Roberto Hernández Cruz**. Toca **Historial de préstamos**.

### Responsivas

8. **`agente.demo`:** abre Operación → Responsivas. En **Equipos en Campo** está el resguardo `CENRES-000002` de Andrea Agente (radio y lámpara, **TURNO**).
   - Toca **Firma** y luego **Hoja**: es el *Resguardo múltiple de activos de seguridad*.
9. Toca **Nuevo Resguardo (Lote)**:
   - Colaborador `1005` (Roberto Hernández).
   - En «Escanea un equipo» escribe `752tsfq505` y Enter; luego `ch-001` y Enter. Se agregan solos.
   - Pon el chaleco en **Fijo**.
   - Firma en el recuadro y toca **Guardar Lote de Resguardo**. Anota el folio.
10. Abre Padrones → Equipos de seguridad: el radio `752TSFQ505` está **ASIGNADO** y dice **A cargo de: Roberto Hernández Cruz**.
11. Vuelve a Responsivas y toca **Recibir Lote Completo (OK)** en tu resguardo nuevo: los equipos vuelven a **DISPONIBLE** y el lote pasa a **Historial Devueltos**.
12. Toca el **reloj** de un equipo para ver su historial.
13. **`agente2.demo`** (Playa): solo ve el resguardo `PLARES-000003`.

## Para recordar

- **Escanear primero**: la llave (o el equipo) y luego el gafete. Con un lector USB no hace falta tocar nada más que Enter.
- **Registrar y Capturar Siguiente** deja el cuadro abierto y limpio; la sede se queda.
- Una llave **no se presta dos veces**: el sistema y la base lo impiden.
- **Anular** es para errores de captura, no se borra nada. Solo Jefe y Administrador.
- Un resguardo **no se guarda sin la firma**. La firma solo la ve quien tiene permiso en esa sede.
- Si un equipo se perdió en campo: baja con voucher en Equipos y después **Recibir Lote Completo**.

## Autoevaluación

1. ¿Qué pasa si escaneo una llave que ya está fuera? *(El sistema avisa en rojo y no la deja elegir)*
2. ¿El Agente puede anular un préstamo? *(No; lo hacen el Jefe de seguridad o el Administrador)*
3. ¿Qué identificación se elige si la persona no deja nada? *(Ninguna (Riesgo))*
4. ¿Puedo entregar un equipo EN MANTENIMIENTO? *(No, solo DISPONIBLES de la sede)*
5. ¿Dónde se guarda la firma del resguardo? *(En la plataforma, fuera de la carpeta pública; solo se ve con permiso)*
6. ¿Qué estado tiene un equipo mientras está en un resguardo? *(ASIGNADO)*
