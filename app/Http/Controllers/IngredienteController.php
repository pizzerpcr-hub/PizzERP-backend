<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Ingrediente;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationException;

class IngredienteController extends Controller
{
    private const ALLOWED_STATUSES = ['ACTIVO', 'INACTIVO'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizedManager($request);

        return $this->listResponse($request,
            Ingrediente::query()->select(['id_ingrediente', 'nombre', 'unidad_medida', 'cantidad_disponible', 'estado'])
                ->orderBy('nombre')->orderBy('id_ingrediente'),
            'ingredientes',
            search: fn ($query, string $term) => $query->whereLike('nombre', "%{$term}%")
        );
    }

    public function show(Request $request, Ingrediente $ingrediente): JsonResponse
    {
        $this->authorizedManager($request);

        return response()->json(['ingrediente' => $ingrediente]);
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->authorizedManager($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules(), $this->validationMessages());

        $ingrediente = DB::transaction(function () use ($data, $manager): Ingrediente {
            $ingrediente = Ingrediente::create([
                ...$data,
                'cantidad_disponible' => $data['cantidad_disponible'] ?? '0.00',
                'estado' => $data['estado'] ?? 'ACTIVO',
            ]);

            $this->recordAudit(
                $manager,
                "Ingrediente {$ingrediente->nombre} (ID {$ingrediente->id_ingrediente}) creado.",
                "Cantidad inicial: {$ingrediente->cantidad_disponible} {$ingrediente->unidad_medida}; estado: {$ingrediente->estado}."
            );

            return $ingrediente;
        });

        return response()->json([
            'message' => 'Ingrediente creado exitosamente.',
            'ingrediente' => $ingrediente,
        ], 201);
    }

    public function update(Request $request, Ingrediente $ingrediente): JsonResponse
    {
        $manager = $this->authorizedManager($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules(true), $this->validationMessages());

        $updatedIngredient = DB::transaction(function () use ($data, $ingrediente, $manager): Ingrediente {
            $lockedIngredient = Ingrediente::query()
                ->whereKey($ingrediente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedIngredient->fill(collect($data)->except('motivo')->all());
            $changedFields = array_keys($lockedIngredient->getDirty());

            if ($changedFields === []) {
                return $lockedIngredient;
            }

            if (blank($data['motivo'] ?? null)) {
                throw ValidationException::withMessages([
                    'motivo' => 'El motivo de la modificación es obligatorio.',
                ]);
            }

            $previousQuantity = $lockedIngredient->getOriginal('cantidad_disponible');
            $previousUnit = $lockedIngredient->getOriginal('unidad_medida');
            $lockedIngredient->save();

            $reason = $data['motivo'].' Campos modificados: '.implode(', ', $changedFields).'.';

            $auditType = 'GESTION_INGREDIENTES';

            if (in_array('cantidad_disponible', $changedFields, true)) {
                $auditType = 'MOVIMIENTO_INVENTARIO';
                $movement = $previousUnit !== $lockedIngredient->unidad_medida
                    ? 'AJUSTE'
                    : ((float) $lockedIngredient->cantidad_disponible > (float) $previousQuantity
                        ? 'ENTRADA'
                        : 'SALIDA');
                $reason .= " Movimiento: {$movement}. Cantidad: {$previousQuantity} {$previousUnit} -> {$lockedIngredient->cantidad_disponible} {$lockedIngredient->unidad_medida}.";
            }

            $this->recordAudit(
                $manager,
                "Ingrediente ID {$lockedIngredient->id_ingrediente} actualizado.",
                mb_strimwidth($reason, 0, 255),
                $auditType
            );

            return $lockedIngredient;
        });

        return response()->json([
            'message' => 'Ingrediente actualizado exitosamente.',
            'ingrediente' => $updatedIngredient,
        ]);
    }

    public function updateStatus(Request $request, Ingrediente $ingrediente): JsonResponse
    {
        $this->normalizeInput($request);
        $manager = $this->authorizedManager($request);
        $data = $request->validate([
            'estado' => ['required', Rule::in(self::ALLOWED_STATUSES)],
        ], $this->validationMessages());

        $updatedIngredient = DB::transaction(function () use ($ingrediente, $manager, $data): Ingrediente {
            $lockedIngredient = Ingrediente::query()
                ->whereKey($ingrediente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedIngredient->estado !== $data['estado']) {
                $previousStatus = $lockedIngredient->estado;
                $lockedIngredient->estado = $data['estado'];
                $lockedIngredient->save();
                $this->recordAudit(
                    $manager,
                    "Ingrediente {$lockedIngredient->nombre} (ID {$lockedIngredient->id_ingrediente}) cambió de estado.",
                    "Estado: {$previousStatus} -> {$lockedIngredient->estado}."
                );
            }

            return $lockedIngredient;
        });

        return response()->json([
            'message' => 'Estado del ingrediente actualizado exitosamente.',
            'ingrediente' => $updatedIngredient,
        ]);
    }

    public function destroy(Request $request, Ingrediente $ingrediente): Response
    {
        $manager = $this->authorizedManager($request);

        DB::transaction(function () use ($ingrediente, $manager): void {
            $lockedIngredient = Ingrediente::query()
                ->whereKey($ingrediente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->recordAudit(
                $manager,
                "Ingrediente {$lockedIngredient->nombre} (ID {$lockedIngredient->id_ingrediente}) eliminado.",
                "Cantidad al eliminar: {$lockedIngredient->cantidad_disponible} {$lockedIngredient->unidad_medida}; estado: {$lockedIngredient->estado}."
            );

            $lockedIngredient->delete();
        });

        return response()->noContent();
    }

    private function authorizedManager(Request $request): User
    {
        return $this->authorizeModule($request, 'ingredientes');
    }

    private function normalizeInput(Request $request): void
    {
        foreach (['nombre', 'unidad_medida', 'estado', 'motivo'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([
                    $field => $field === 'estado'
                        ? mb_strtoupper(trim($value))
                        : trim($value),
                ]);
            }
        }
    }

    /** @return array<string, array<int, string|In>> */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'nombre' => [...$presence, 'string', 'max:100'],
            'unidad_medida' => [...$presence, 'string', 'max:30'],
            'cantidad_disponible' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'estado' => ['sometimes', 'required', Rule::in(self::ALLOWED_STATUSES)],
            ...($partial ? ['motivo' => ['sometimes', 'nullable', 'string', 'max:120']] : []),
        ];
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'nombre.required' => 'El nombre del ingrediente es obligatorio.',
            'nombre.max' => 'El nombre no puede superar 100 caracteres.',
            'unidad_medida.required' => 'La unidad de medida es obligatoria.',
            'unidad_medida.max' => 'La unidad de medida no puede superar 30 caracteres.',
            'cantidad_disponible.numeric' => 'La cantidad disponible debe ser numérica.',
            'cantidad_disponible.min' => 'La cantidad disponible no puede ser negativa.',
            'cantidad_disponible.max' => 'La cantidad disponible no puede superar 99999999.99.',
            'cantidad_disponible.decimal' => 'La cantidad disponible admite hasta dos decimales.',
            'estado.in' => 'El estado debe ser ACTIVO o INACTIVO.',
            'motivo.required' => 'El motivo de la modificación es obligatorio.',
            'motivo.max' => 'El motivo no puede superar 120 caracteres.',
        ];
    }

    private function recordAudit(
        User $manager,
        string $description,
        string $reason,
        string $type = 'GESTION_INGREDIENTES'
    ): void {
        Bitacora::create([
            'id_usuario' => $manager->id_usuario,
            'descripcion_movimiento' => $description,
            'tipo_movimiento' => $type,
            'motivo' => $reason,
            'fecha' => now(),
        ]);
    }
}
