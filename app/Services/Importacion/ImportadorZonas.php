<?php

namespace App\Services\Importacion;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Autorizador;
use App\Support\Espacios\Etiquetas;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Zonas y edificios: cada fila es un lugar Zona o edificio → Piso →
 * Habitación (u Oficina, según el rubro). Lo que ya existe se reutiliza y
 * solo se crea lo que falta, con el mismo servicio que los botones «Nueva
 * Zona / Edificio», «Nuevo Piso» y «Nueva Habitación» (AdministradorEspacios).
 * Un lugar que ya existe completo se omite: no hay datos que actualizar.
 */
class ImportadorZonas extends Importador
{
    public function __construct(
        private readonly AdministradorEspacios $espacios,
        private readonly Autorizador $autorizador,
    ) {}

    public function tipo(): string
    {
        return 'zonas';
    }

    public function modulo(): string
    {
        return 'espacios';
    }

    public function titulo(): string
    {
        return 'Zonas y edificios';
    }

    public function registro(): string
    {
        return 'lugar';
    }

    public function rutaLista(): string
    {
        return route('espacios.index');
    }

    public function actualizable(): bool
    {
        return false;
    }

    public function nombreClave(): string
    {
        return 'Sede + Zona o edificio + Piso + '.$this->especifica();
    }

    public function columnas(): array
    {
        $especifica = $this->especifica();

        return [
            new Columna('sede', 'Sede', true, 'Sede Centro', 'Nombre o código de la sede, como aparece en Estructura → Sedes.', campos: ['sede_id']),
            new Columna('zona', 'Zona o edificio', true, 'Torre A', 'Si ya existe en esa sede, se usa la que hay.', alias: ['zona', 'edificio', 'zona edificio'], campos: ['edificio']),
            new Columna('piso', 'Piso', false, 'Piso 1', 'Vacío si la '.mb_strtolower($especifica).' cuelga directo de la zona.', alias: ['nivel'], campos: ['area']),
            new Columna('especifica', $especifica, false, '101', 'Vacío si solo quieres crear la zona o el piso.',
                alias: ['habitacion', 'area especifica', 'oficina', 'departamento', 'local', 'cuarto'], campos: ['area_especifica', 'nombre', 'nivel', 'padre_id']),
        ];
    }

    public function clave(array $fila): ?string
    {
        $partes = array_map(fn ($c) => Columna::normalizar((string) $this->valor($fila, $c)), ['sede', 'zona', 'piso', 'especifica']);

        return $partes[0] === '' || $partes[1] === '' ? null : implode('|', $partes);
    }

    public function existente(array $fila): ?Model
    {
        $sede = $this->sedeDeFila($fila);
        $nodo = null;
        foreach ($this->camino($fila) as [$nivel, $nombre]) {
            $nodo = $this->hijo($sede, $nodo, $nivel, $nombre);
            if ($nodo === null) {
                return null;
            }
        }

        return $nodo;
    }

    public function describir(Model $registro): string
    {
        /** @var Espacio $registro */
        $nombres = Espacio::whereIn('id', $registro->idsAncestros())->pluck('nombre', 'id');
        $ruta = collect($registro->idsAncestros())->map(fn ($id) => $nombres[$id] ?? null)->filter()->push($registro->nombre)->implode(' › ');

        return $ruta.($registro->activo ? '' : ' (desactivado)');
    }

    public function editable(User $actor, Model $existente): bool
    {
        return false;
    }

    public function crear(User $actor, int $empresaId, array $fila): Model
    {
        $sede = $this->sedeDeFila($fila);
        $permitidas = $this->autorizador->sedesPermitidas($actor, 'espacios.crear');
        if (! $sede->activo || ($permitidas !== null && ! in_array((int) $sede->id, $permitidas, true))) {
            throw ValidationException::withMessages(['sede' => $sede->activo
                ? "No puedes crear zonas en «{$sede->nombre}»: solo en tus sedes."
                : "La sede «{$sede->nombre}» está desactivada."]);
        }

        $nodo = null;
        foreach ($this->camino($fila) as [$nivel, $nombre, $columna]) {
            $existente = $this->hijo($sede, $nodo, $nivel, $nombre);
            if ($existente !== null) {
                $nodo = $existente;

                continue;
            }
            try {
                $nodo = $this->espacios->crear($actor, $sede, $nodo, $nivel, ['nombre' => mb_substr($nombre, 0, 100)]);
            } catch (ValidationException $e) {
                // El error es de la celda de este nivel (nombre repetido, lugar desactivado…)
                throw ValidationException::withMessages([$columna => collect($e->errors())->flatten()->first()]);
            }
        }

        return $nodo;
    }

    public function actualizar(User $actor, int $empresaId, Model $existente, array $fila): Model
    {
        return $existente;
    }

    // ------------------------------------------------------------------ Apoyo

    private function especifica(): string
    {
        $empresaId = app(Tenant::class)->empresaId();

        return Etiquetas::para($empresaId === null ? null : Empresa::with('rubro')->find($empresaId))[Espacio::AREA_ESPECIFICA]['singular'];
    }

    private function sedeDeFila(array $fila): Sede
    {
        $sede = $this->sede($this->valor($fila, 'sede'));
        if ($sede === null) {
            throw ValidationException::withMessages(['sede' => 'Escribe la sede.']);
        }

        return $sede;
    }

    /**
     * Los niveles que trae la fila, de arriba abajo.
     *
     * @return list<array{0: string, 1: string, 2: string}> [nivel, nombre, columna]
     */
    private function camino(array $fila): array
    {
        $camino = [];
        $zona = $this->valor($fila, 'zona');
        if ($zona === null) {
            throw ValidationException::withMessages(['zona' => 'Escribe la zona o edificio.']);
        }
        $camino[] = [Espacio::EDIFICIO, $zona, 'zona'];
        if (($piso = $this->valor($fila, 'piso')) !== null) {
            $camino[] = [Espacio::AREA, $piso, 'piso'];
        }
        if (($especifica = $this->valor($fila, 'especifica')) !== null) {
            $camino[] = [Espacio::AREA_ESPECIFICA, $especifica, 'especifica'];
        }

        return $camino;
    }

    /** Hijo con ese nombre (sin importar mayúsculas) en ese lugar de la sede. */
    private function hijo(Sede $sede, ?Espacio $padre, string $nivel, string $nombre): ?Espacio
    {
        return Espacio::where('sede_id', $sede->id)
            ->when($padre === null, fn ($q) => $q->whereNull('padre_id'), fn ($q) => $q->where('padre_id', $padre->id))
            ->where('nivel', $nivel)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower(trim($nombre))])
            ->first();
    }
}
