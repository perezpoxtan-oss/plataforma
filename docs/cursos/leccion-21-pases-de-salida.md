# Lección 21 — Pases de salida

**Para:** Agentes, Supervisores, Jefes de seguridad, Directores y Administradores · **Duración:** 25 minutos

## Idea clave

Un pase de salida es el permiso para que un equipo **salga** de la sede. Pasa por **secciones de firmas**; cuando firman todos los de una sección, el pase avanza solo. Si el equipo debe regresar, el pase no se cierra hasta que regresa.

## Práctica (en QA)

1. **`admin.demo`:** abre Operación → Pases de salida.
   - Toca **Vencidos**: aparece `PS-000009` (una puerta en reparación que debió regresar).
   - Busca `752TSFQ505`: aparece `PS-000005`, el radio prestado a Playa.
2. Crea un pase: **Nuevo Pase**, sede **Hotel Demo Centro**, motivo **Préstamo**, solicitante: escribe `mariana` y elige a Mariana López Pech. Destino: **Otra Sede** → **Hotel Demo Playa** (mira cómo se llena la dirección). Fecha tentativa de regreso: dentro de una semana. En artículos escanea o escribe `752TSFQ504` en «Escanear un equipo del padrón» y pulsa Enter: se llena el radio. Guarda.
3. Abre tu pase (**Ver / Aprobar**) y firma **Jefe de Departamento**, **Contraloría** y **Gerencia**. Al tercero, el pase queda **Aprobado, listo para salir**.
4. **`agente.demo`** (otra ventana privada): abre `PS-000001`. Ve el recuadro amarillo: el Agente **no aprueba**. Abre `PS-000003`… no lo ve: es de Playa. Abre tu pase aprobado y firma la **Salida Física** (el nombre de Seguridad ya viene escrito).
5. **`agente2.demo`** (Playa): abre tu pase: ahora le toca la **Recepción en Destino**. Firma los cuatro roles. Luego firma la **Salida de Regreso**.
6. **`agente.demo`:** firma la **Recepción de Regreso**. El pase queda **Regresado / Cerrado**. Toca **Imprimir Pase**.
7. **`director.demo`:** abre `PS-000001` y **recházalo** con el motivo «Sin evento programado». Fíjate que ya no se puede firmar.

## Para recordar

- **Venta** y **Traspaso Definitivo** no regresan: al salir quedan **Salió — Cerrado**.
- Si va a un **proveedor** o se lo lleva un **colaborador**, después de salir solo falta la **Recepción de Regreso**.
- **Aprobar** y **Rechazar** piden el permiso «Aprobar»; las demás firmas, «Firmar». La sede destino firma lo que pasa en su sede.
- Si el solicitante no existe, usa **Nuevo Colaborador**: es un alta provisional que Recursos Humanos valida.
- La **Fecha Tentativa de Regreso** es la que marca los **Vencidos**: escríbela siempre que el equipo deba volver.
