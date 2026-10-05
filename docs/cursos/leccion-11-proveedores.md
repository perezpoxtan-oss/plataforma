# Lección 11 — Empresas Externas (Proveedores)

**Para:** Administrador del cliente, Jefe de seguridad y Supervisor · **Duración:** 15 minutos

## Práctica (en QA)

Como `admin.demo`:

1. Abre **Padrones → Proveedores**. Verás 9 empresas demo; *Fletes Rápidos del Sureste* aparece como **BAJA / VETADA**.
2. Toca la píldora **Contratistas**: queda solo *Constructora Maya*, que dice **1 de 2 sedes** (solo Playa).
3. En el filtro de sedes elige **Hotel Demo Centro**: *Constructora Maya* y *Tours Xcaret Express* desaparecen.
4. Toca **Registrar Empresa Externa** y escribe "abarrotes del caribe": te avisa que ya existe. Cámbialo a **Lavandería Industrial del Sur**, RFC `lis101010ab1`, teléfono `998 100 2000`, y guarda. Abre la ficha: el RFC quedó en mayúsculas y el teléfono sin espacios.
5. Intenta un RFC `ABC123` o un teléfono `998-ABC`: el sistema los rechaza con un mensaje claro.
6. Abre la **Ficha** de *Transportes Kin-Ha* y recorre **Resumen**, **Personal** y **Flotilla**.
7. Desactiva la lavandería con ⊘ y reactívala con ↺.

Como `supervisor.demo` (sede Centro):

8. Abre Proveedores: no ves *Constructora Maya* (solo opera en Playa).
9. Registra **Constructora Maya**: el sistema dice "Ya existía «Constructora Maya»: se agregó a tu sede". Ahora la ves en tu lista y su botón de sedes dice **Todas las sedes**, porque ya opera en las dos sedes que existen hoy (Centro se agregó a la lista; una sede futura no la tendrá).
10. Intenta editar *Taxis Aeropuerto*: no hay lápiz, porque también opera en Playa.

## Para recordar

- *Todas las sedes* incluye las sedes que se abran después.
- Un mismo proveedor nunca se duplica en la empresa: mayúsculas y espacios no cuentan.
- Quien trabaja en una sola sede registra para su sede y solo modifica lo que es exclusivo de su sede.
- Una empresa **BAJA / VETADA** no se puede usar en pases ni accesos.

## Autoevaluación

1. El Jefe de la sede Playa registra "TAXIS AEROPUERTO", que ya existe en todas las sedes. ¿Qué pasa? *(No se duplica; avisa que ya opera en su sede)*
2. ¿Quién puede cambiar el teléfono de una empresa que opera en Centro y Playa? *(Quien tiene alcance de empresa, p. ej. el Administrador)*
3. ¿Dónde ves el personal de una empresa externa? *(En su Ficha, pestaña Personal)*
