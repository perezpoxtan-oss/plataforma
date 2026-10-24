# Lección 61 · La plataforma según el giro de tu empresa

**Duración:** 30 minutos · **Para:** Administrador del cliente, Supervisor y Operador de caseta (prácticas 1 a 4); Integrador técnico (práctica 5).

Aprenderás:

1. Qué cambia en la plataforma según el giro (hotel, corporativo, condominio o fraccionamiento).
2. A registrar en la Bitácora de accesos los tipos de persona de cada giro.
3. A registrar la visita de un residente con el departamento o la casa a la que va.
4. A reconocer que el manual, los menús y los avisos usan las palabras de tu giro.
5. (Integrador) Dónde vive la regla y cómo se escribe el manual para varios giros.

## Antes de empezar

Usa las tres empresas de prueba: la del **hotel**, la **corporativa** y la del **condominio**, cada una con su cuenta de agente de caseta y de administrador (tu instructor te da los usuarios). Abre cada una en una ventana distinta para comparar.

## Lo que hay que saber

- El **giro** (rubro) de la empresa lo elige quien la da de alta. En un **hotel** todo funciona como siempre.
- En un **corporativo** no hay huéspedes: quien llega de fuera es un **Visitante** y los lugares son **oficinas**.
- En un **condominio** quien vive ahí es un **Residente**, quien llega es una **Visita** (a un departamento) y los proveedores, la limpieza o el jardinero entran como **Servicio**. En un **fraccionamiento** es igual, con **casas**.
- Los registros de antes **no se pierden**: si una empresa cambió de giro, lo que ya estaba en la bitácora se sigue viendo con su nombre.
- El **formato de Accidente** no cambia en ningún giro.

## Práctica 1 · Comparar el Nuevo Ingreso (Operador de caseta)

1. En la empresa del hotel, entra a **Operación → Caseta → Bitácora de accesos** y toca **Nuevo Ingreso**. Anota los seis tipos de persona.
2. Haz lo mismo en la empresa corporativa y en la del condominio.

Comprueba: el hotel ofrece **Huésped**; el corporativo, **Visitante** (y no Huésped); el condominio, **Residente**, **Visita** y **Servicio / Proveedor**. En el filtro **Todos los tipos** de cada empresa salen solo sus tipos.

## Práctica 2 · Registrar la visita a un residente (Operador de caseta, condominio)

1. **Nuevo Ingreso** → **Visita**.
2. Escribe el nombre de la visita y deja la identificación que entrega.
3. En **Motivo de la Visita** deja **Visita a Residente**, busca al residente en **¿A quién visita?** y escribe o elige el departamento en **¿A qué departamento va?**.
4. Toca **Registrar y dejar pasar**.

Comprueba: la tarjeta en **Gente en Sitio** dice **VISITA**, la pastilla **Depto. 101** junto al nombre y **Motivo: Visita a Residente**. Escribe «101» en el campo de **Dar salida**: aparece la visita.

## Práctica 3 · Un servicio que espera (Operador de caseta, condominio)

1. **Nuevo Ingreso** → **Servicio / Proveedor**.
2. Escribe el nombre, la empresa (por ejemplo «Limpieza del hogar»), el residente que lo citó en **Host — ¿Quién lo citó?** y, si aplica, el área de la administración.
3. Registra.

Comprueba: la tarjeta queda en **Pendientes de Autorización** con la pastilla **SERVICIO** y espera la confirmación, igual que un proveedor en el hotel.

## Práctica 4 · Las palabras en toda la plataforma (Administrador del cliente)

1. En la empresa del condominio, abre el menú: el padrón de personas que viven ahí se llama **Residentes**.
2. Abre **Lost & Found** en el hotel y el mismo módulo en el condominio: en el condominio se llama **Objetos olvidados** y habla de «su dueño», no del huésped.
3. Abre **Manual → Bitácora de accesos** en cada empresa y busca «residente» en el manual del condominio.
4. En **Operación → Reservas → Configuración**, revisa **Servicios y requerimientos**: el botón **Cargar servicios sugeridos de hotel** solo aparece en el hotel.

Comprueba: cada empresa lee el manual con sus palabras; los enlaces del manual llevan al mismo apartado en las tres.

## Práctica 5 · Para el integrador técnico

1. Lee `docs/tecnico/giros.md`: un solo ayudante (`App\Support\Terminos`) decide los tipos de acceso, los motivos y las palabras; el middleware `AplicarTerminos` adapta las páginas y los correos.
2. Abre `docs/usuario/GUIA.md` y localiza cómo se marcan en el manual las partes de un solo giro (`<!-- solo: hotel -->` … `<!-- fin -->`) y las que no se sustituyen (`<!-- sin cambiar -->`).
3. Intenta registrar un Huésped en la empresa corporativa mandando el formulario a mano: el servidor responde «Ese tipo de persona no se registra en esta empresa. Elige uno de la lista.».

## Repaso

- ¿Qué tipo usa la caseta de un condominio para la señora que hace la limpieza? (**Servicio / Proveedor**.)
- ¿Dónde se anota a qué departamento va una visita? (**¿A qué departamento va?**, dentro de Visita.)
- ¿Qué pasa con los huéspedes registrados antes de cambiar una empresa a corporativo? (Se siguen viendo como **Huésped**.)
