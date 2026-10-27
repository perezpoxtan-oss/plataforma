<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Espacio;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Espacios\Lugares;
use App\Support\Terminos;
use Illuminate\Validation\ValidationException;

/**
 * C-09 (DD-12): un solo campo «¿A quién viene?» en el Nuevo ingreso. Es un
 * buscador con sugerencias que reemplaza a «Host — ¿Quién lo citó?», «¿A qué
 * departamento viene?», «¿A quién visita?» y «¿A qué departamento/casa va?».
 *
 * Lo que se elige viaja como una clave corta (`a_quien`):
 *  - `c:ID`  una persona del directorio (personal o residente);
 *  - `d:ID`  un área (departamento);
 *  - `e:ID`  un lugar del catálogo (vivienda en residenciales, habitación en hotel);
 *  - `h:ID`  un huésped que está en sitio (hotel, «Visita a huésped»).
 * Sin clave se usa el texto escrito (`a_quien_texto`): «Visita general», el
 * nombre del huésped o una vivienda que no está en el catálogo («sin catálogo»).
 *
 * aplicar() traduce la clave a los campos de siempre (visita_colaborador_id,
 * host_colaborador_id, departamento_id, habitacion, espacio_id, persona_visita),
 * así el resto de las reglas de la caseta (y los formularios de antes o la
 * app) no cambian. El departamento se deduce de la persona.
 *
 * ¿Quién autoriza? (lo decide RecepcionEnCaseta / RegistroAccesos):
 *  - persona con cuenta → la persona (su departamento es el suplente);
 *  - persona sin cuenta o área → el responsable del departamento (como antes);
 *  - residente o vivienda (condominio, fraccionamiento) y huésped (hotel) →
 *    la caseta llama: «Llamé: autorizó» / «No autorizó» deja constancia.
 */
class AQuienViene
{
    public function __construct(private readonly Lugares $lugares) {}

    /** ¿Con qué tipo y motivo se pregunta «¿A quién viene?»? */
    public static function contexto(string $tipo, ?string $motivo): ?string
    {
        if (in_array($tipo, Acceso::CON_AUTORIZACION, true)) {
            return 'proveedor';
        }
        if ($tipo !== 'visitante') {
            return null;
        }

        return match ($motivo) {
            'colaborador', 'departamento' => 'visita',
            'huesped' => 'huesped',
            default => null,
        };
    }

    /**
     * Sugerencias al escribir (una sola lista: personas, áreas y lugares).
     *
     * @param  list<int>|null  $sedes
     * @return list<array<string, mixed>>
     */
    public function sugerencias(string $texto, ?array $sedes, string $contexto, Terminos $t): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 1) {
            return [];
        }
        $resultado = [];

        if ($contexto === 'huesped') {
            // Huéspedes en sitio con su habitación, y las habitaciones del catálogo
            $comodin = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
            foreach (Acceso::where('tipo', 'huesped')->where('movimiento', 'entrada')->whereIn('estado', Acceso::ESTADOS_ABIERTOS)
                ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes === [] ? [0] : $sedes))
                ->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$comodin])->orWhereRaw('LOWER(habitacion) LIKE ?', [$comodin]))
                ->orderBy('nombre')->limit(6)->get(['id', 'nombre', 'habitacion', 'espacio_id']) as $h) {
                $resultado[] = ['clave' => 'h:'.$h->id, 'tipo' => 'huesped', 'titulo' => mb_convert_case(mb_strtolower($h->nombre), MB_CASE_TITLE),
                    'detalle' => 'Huésped en sitio'.($h->habitacion ? ' · Hab. '.$h->habitacion : ''), 'llamar' => true];
            }
            foreach ($this->lugares->buscar($texto, $sedes, Lugares::ESPECIFICOS, 6) as $l) {
                $resultado[] = ['clave' => 'e:'.$l['id'], 'tipo' => 'lugar', 'titulo' => $t->espacio().' '.$l['nombre'], 'detalle' => $l['ruta'], 'llamar' => true];
            }

            return $resultado;
        }

        // Personas del directorio (en residenciales, primero los residentes con su vivienda)
        foreach (app(AdministradorColaboradores::class)->buscar($texto, $sedes, false)['resultados'] as $c) {
            $residente = (bool) ($c['residente'] ?? false);
            $resultado[] = ['clave' => 'c:'.$c['id'], 'tipo' => $residente ? 'residente' : 'persona', 'titulo' => $c['nombre_completo'],
                'detalle' => $residente
                    ? $t->residente().($c['vivienda'] ? ' · '.$t->espacio().' '.$c['vivienda'] : '')
                    : trim(($c['departamento'] ?? 'Sin departamento').($c['puesto'] ? ' · '.$c['puesto'] : '')),
                'departamento_id' => $c['departamento_id'], 'llamar' => $residente];
        }
        usort($resultado, fn ($a, $b) => ($a['tipo'] === 'residente' ? 0 : 1) <=> ($b['tipo'] === 'residente' ? 0 : 1));
        $resultado = array_slice($resultado, 0, 8);

        // Viviendas (residenciales): la vivienda y quién vive ahí
        if ($t->esResidencial()) {
            foreach ($this->lugares->buscar($texto, $sedes, Lugares::ESPECIFICOS, 5) as $l) {
                $quienes = $this->lugares->residentesDe($l['id']);
                $resultado[] = ['clave' => 'e:'.$l['id'], 'tipo' => 'lugar', 'titulo' => $t->espacio().' '.$l['nombre'],
                    'detalle' => $quienes->isEmpty() ? $l['ruta'] : $quienes->map(fn ($r) => $r->nombreCompleto())->join(', '), 'llamar' => true];
            }
        }

        // Áreas (departamentos) de la sede
        $comodin = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
        foreach (Departamento::where('activo', true)->when($sedes !== null, fn ($q) => $q->aplicanEn($sedes === [] ? [0] : $sedes))
            ->whereRaw('LOWER(nombre) LIKE ?', [$comodin])->orderBy('nombre')->limit(5)->get(['id', 'nombre']) as $d) {
            $resultado[] = ['clave' => 'd:'.$d->id, 'tipo' => 'area', 'titulo' => $d->nombre, 'detalle' => $t->esResidencial() ? 'Área de la administración' : 'Área (departamento)',
                'departamento_id' => (int) $d->id, 'llamar' => false];
        }

        return $resultado;
    }

    /**
     * Traduce «¿A quién viene?» a los campos de siempre. Devuelve si la caseta
     * debe llamar (residente, vivienda o huésped) y a quién.
     *
     * @param  array<string, mixed>  $d  datos ya validados del formulario (se modifican)
     * @return array{llamar: bool, persona: ?Colaborador, lugar: ?Espacio, sin_catalogo: bool}
     */
    public function aplicar(string $tipo, array &$d, int $sedeId, Terminos $t): array
    {
        $r = ['llamar' => false, 'persona' => null, 'lugar' => null, 'sin_catalogo' => false];
        $motivo = $d['motivo_visita'] ?? null;
        $contexto = self::contexto($tipo, $motivo);
        $clave = is_string($d['a_quien'] ?? null) ? trim($d['a_quien']) : '';
        $texto = is_string($d['a_quien_texto'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $d['a_quien_texto'])) : '';
        if ($contexto === null || ($clave === '' && $texto === '')) {
            // Formularios de antes (o la app): los campos de siempre, más la llamada al residente si viene a uno
            if ($contexto !== null && $t->esResidencial()) {
                $id = $tipo === 'visitante' ? ($d['visita_colaborador_id'] ?? null) : ($d['host_colaborador_id'] ?? null);
                $r['persona'] = $id ? Colaborador::find((int) $id) : null;
                $r['llamar'] = (bool) $r['persona']?->esResidente();
            }

            return $r;
        }
        if (! preg_match('/^([cdeh]):(\d+)$/', $clave, $m)) {
            $m = null;
        }

        if ($m === null) {
            // Texto libre: visita general (lo escribe), nombre del huésped o una vivienda que no está en el catálogo
            if ($contexto === 'huesped') {
                $d['persona_visita'] = $texto;
                $r['llamar'] = true;
            } elseif ($t->esResidencial()) {
                $lugar = $this->lugares->porTexto($texto, $sedeId);
                if ($lugar !== null) {
                    return $this->conLugar($tipo, $d, $lugar, $t, $r);
                }
                $d['habitacion'] = mb_substr($texto, 0, 20);
                $d['motivo_visita'] = $tipo === 'visitante' ? 'colaborador' : $motivo;
                $r['llamar'] = true;
                $r['sin_catalogo'] = true;
            } else {
                throw ValidationException::withMessages(['a_quien' => 'Elige de la lista a quién viene (una persona o un área). Si no aparece, búscala con otra palabra.']);
            }

            return $r;
        }

        [, $letra, $id] = $m;
        $id = (int) $id;
        switch ($letra) {
            case 'c':
                $persona = Colaborador::where('activo', true)->whereNull('fusionado_en_id')->find($id);
                if ($persona === null) {
                    throw ValidationException::withMessages(['a_quien' => 'La persona elegida ya no está activa. Búscala de nuevo.']);
                }
                $r['persona'] = $persona;
                if ($tipo === 'visitante') {
                    $d['visita_colaborador_id'] = $persona->id;
                    $d['motivo_visita'] = 'colaborador';
                } else {
                    $d['host_colaborador_id'] = $persona->id;
                    // El departamento se deduce de la persona (si aplica en la sede)
                    if (empty($d['departamento_id']) && $persona->departamento_id !== null
                        && Departamento::where('activo', true)->aplicanEn([$sedeId])->whereKey($persona->departamento_id)->exists()) {
                        $d['departamento_id'] = (int) $persona->departamento_id;
                    }
                }
                if ($persona->esResidente()) {
                    $r['llamar'] = true;
                    if ($persona->espacio_id !== null && ($lugar = Espacio::find($persona->espacio_id)) !== null) {
                        $d['habitacion'] = mb_substr((string) $lugar->nombre, 0, 20);
                        $d['espacio_id'] = (int) $lugar->id;
                        $r['lugar'] = $lugar;
                    }
                }
                break;
            case 'd':
                if ($tipo === 'visitante') {
                    $d['motivo_visita'] = 'departamento';
                    $d['visita_colaborador_id'] = null;
                }
                $d['departamento_id'] = $id;
                break;
            case 'e':
                $lugar = $this->lugares->valido($id, [$sedeId], Lugares::ESPECIFICOS);
                if ($lugar === null) {
                    throw ValidationException::withMessages(['a_quien' => 'Ese lugar no es de esta sede o está dado de baja. Búscalo de nuevo.']);
                }

                return $this->conLugar($tipo, $d, $lugar, $t, $r);
            case 'h':
                $huesped = Acceso::where('tipo', 'huesped')->where('sede_id', $sedeId)->whereIn('estado', Acceso::ESTADOS_ABIERTOS)->find($id);
                if ($huesped === null) {
                    throw ValidationException::withMessages(['a_quien' => 'Ese huésped ya no está en sitio. Escribe su nombre o su habitación.']);
                }
                $d['persona_visita'] = $huesped->nombre;
                $d['habitacion'] = $huesped->habitacion;
                $d['espacio_id'] = $huesped->espacio_id;
                $r['llamar'] = true;
                break;
        }

        return $r;
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array{llamar: bool, persona: ?Colaborador, lugar: ?Espacio, sin_catalogo: bool}  $r
     * @return array{llamar: bool, persona: ?Colaborador, lugar: ?Espacio, sin_catalogo: bool}
     */
    private function conLugar(string $tipo, array &$d, Espacio $lugar, Terminos $t, array $r): array
    {
        $d['habitacion'] = mb_substr((string) $lugar->nombre, 0, 20);
        $d['espacio_id'] = (int) $lugar->id;
        $r['lugar'] = $lugar;
        $r['llamar'] = true;
        if ($t->esResidencial()) {
            // Con un solo residente en la vivienda, la visita queda ligada a él
            $quienes = $this->lugares->residentesDe((int) $lugar->id);
            if ($quienes->count() === 1) {
                $r['persona'] = $quienes->first();
                $d[$tipo === 'visitante' ? 'visita_colaborador_id' : 'host_colaborador_id'] = $quienes->first()->id;
            } elseif ($quienes->isNotEmpty()) {
                $d['persona_visita'] = mb_substr(mb_strtoupper($quienes->map(fn ($q) => $q->nombreCompleto())->join(', ')), 0, 150);
            }
            if ($tipo === 'visitante') {
                $d['motivo_visita'] = 'colaborador';
            }
        }

        return $r;
    }

    /** El texto que se vuelve a pintar tras un error (la clave elegida). */
    public function titulo(?string $clave, Terminos $t): ?string
    {
        if (! is_string($clave) || ! preg_match('/^([cdeh]):(\d+)$/', $clave, $m)) {
            return null;
        }

        return match ($m[1]) {
            'c' => Colaborador::find((int) $m[2])?->nombreCompleto(),
            'd' => Departamento::whereKey((int) $m[2])->value('nombre'),
            'e' => ($e = Espacio::find((int) $m[2])) ? $t->espacio().' '.$e->nombre : null,
            'h' => Acceso::whereKey((int) $m[2])->value('nombre'),
            default => null,
        };
    }

    /** La cuenta activa de una persona del directorio (quien autoriza su propia visita). */
    public static function cuentaDe(?Colaborador $persona): ?User
    {
        if ($persona === null || $persona->esResidente()) {
            return null;
        }

        return User::where('colaborador_id', $persona->id)->where('empresa_id', $persona->empresa_id)->where('activo', true)->first();
    }
}
