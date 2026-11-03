<?php

namespace Tests\Feature\Asistencia;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Sede;
use App\Models\Turno;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;

/**
 * Escenario de Asistencia (ADR-0024): un hotel en la hora del centro de
 * México con dos departamentos, un turno matutino (08:00 a 16:00, lunes a
 * viernes) y uno nocturno (22:00 a 06:00). «Hoy» es el miércoles 4 de
 * noviembre de 2026 a las 12:00 (hora local).
 */
trait EscenarioAsistencia
{
    use CreaDatosNucleo;

    protected Empresa $empresa;

    protected Sede $sede;

    protected Departamento $cocina;

    protected Departamento $recepcion;

    protected Turno $matutino;

    protected Turno $nocturno;

    protected User $rh;

    protected User $jefe;

    protected User $agente;

    protected const ZONA = 'America/Mexico_City';

    protected function prepararAsistencia(bool $encender = true): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 12:00', self::ZONA)->utc());
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Centro');
        $this->empresa->forceFill(['zona_horaria' => self::ZONA])->save();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->enEmpresa(fn () => $this->sede->forceFill(['zona_horaria' => self::ZONA])->save());
        $this->enEmpresa(function () {
            $this->cocina = Departamento::create(['nombre' => 'Cocina']);
            $this->recepcion = Departamento::create(['nombre' => 'Recepción']);
            $this->matutino = Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '08:00', 'hora_fin' => '16:00', 'dias_laborables' => '1,2,3,4,5']);
            $this->nocturno = Turno::create(['nombre' => 'Nocturno', 'hora_inicio' => '22:00', 'hora_fin' => '06:00']);
        });
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->sede);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->sede);
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->cocina->id, 'user_id' => $this->jefe->id, 'sede_id' => null]));
        if ($encender) {
            $this->encenderAsistencia();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function encenderAsistencia(?Empresa $empresa = null): void
    {
        $empresa ??= $this->empresa;
        DB::table('empresa_modulos')->updateOrInsert(
            ['empresa_id' => $empresa->id, 'modulo_id' => Modulo::where('clave', 'asistencia')->value('id')],
            ['activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        app(Autorizador::class)->olvidar();
        Autorizador::olvidarEsquema();
    }

    protected function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    protected function colaborador(string $nombre, ?Turno $turno, ?Departamento $depto = null, string $alta = '2026-10-01'): Colaborador
    {
        return $this->enEmpresa(function () use ($nombre, $turno, $depto, $alta) {
            $c = Colaborador::create(['num_empleado' => 'E-'.mt_rand(1000, 99999), 'nombre' => $nombre, 'apellido_paterno' => 'Prueba',
                'sede_id' => $this->sede->id, 'departamento_id' => ($depto ?? $this->cocina)->id, 'turno_id' => $turno?->id]);
            $c->forceFill(['created_at' => CarbonImmutable::parse($alta.' 09:00', self::ZONA)->utc()])->saveQuietly();

            return $c;
        });
    }

    /** Entrada (y salida) en hora local, como las registra la caseta, el lector o un equipo de acceso. */
    protected function marcaje(Colaborador $c, string $entrada, ?string $salida = null, ?Sede $sede = null): Acceso
    {
        return $this->enEmpresa(function () use ($c, $entrada, $salida, $sede) {
            $a = Acceso::create([
                'sede_id' => ($sede ?? $this->sede)->id, 'tipo' => 'colaborador', 'colaborador_id' => $c->id, 'nombre' => mb_strtoupper($c->nombreCompleto()),
                'estado' => 'en_sitio', 'entrada_at' => CarbonImmutable::parse($entrada, self::ZONA)->utc(),
            ]);
            if ($salida !== null) {
                $a->forceFill(['estado' => 'finalizado', 'salida_at' => CarbonImmutable::parse($salida, self::ZONA)->utc()])->save();
            }

            return $a;
        });
    }
}
