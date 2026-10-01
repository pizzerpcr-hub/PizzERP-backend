<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Categoria;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class CategoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);

        return response()->json([
            'categorias' => Categoria::query()->withCount('productos')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules());
        $categoria = DB::transaction(function () use ($data, $manager): Categoria {
            $categoria = Categoria::create([
                ...$data,
                'estado' => $data['estado'] ?? 'ACTIVO',
            ]);
            $this->recordAudit($manager, "Categoría {$categoria->nombre} (ID {$categoria->id_categoria}) creada.");

            return $categoria;
        });
        $categoria->loadCount('productos');

        return response()->json(['categoria' => $categoria], 201);
    }

    public function update(Request $request, Categoria $categoria): JsonResponse
    {
        $manager = $this->authorizeAdministrator($request);
        $this->normalizeInput($request);

        $data = $request->validate($this->rules(true));
        DB::transaction(function () use ($categoria, $data, $manager): void {
            $categoria->fill($data);

            if ($categoria->isDirty()) {
                $categoria->save();
                $this->recordAudit($manager, "Categoría {$categoria->nombre} (ID {$categoria->id_categoria}) actualizada.");
            }
        });
        $categoria->loadCount('productos');

        return response()->json(['categoria' => $categoria]);
    }

    private function authorizeAdministrator(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || mb_strtoupper(trim((string) $user->rol)) !== 'ADMINISTRADOR') {
            abort(403, 'No tiene permiso para gestionar categorías.');
        }

        return $user;
    }

    private function normalizeInput(Request $request): void
    {
        foreach (['nombre', 'descripcion', 'estado'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $request->merge([$field => $field === 'estado' ? mb_strtoupper(trim($value)) : trim($value)]);
            }
        }
    }

    /** @return array<string, array<int, string|In>> */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? ['sometimes', 'required'] : ['required'];

        return [
            'nombre' => [...$presence, 'string', 'max:80'],
            'descripcion' => [...$presence, 'string', 'max:150'],
            'estado' => ['sometimes', 'required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }

    private function recordAudit(User $manager, string $description): void
    {
        Bitacora::create([
            'id_usuario' => $manager->id_usuario,
            'descripcion_movimiento' => $description,
            'tipo_movimiento' => 'GESTION_CATEGORIAS',
            'motivo' => 'Gestión del catálogo de categorías.',
            'fecha' => now(),
        ]);
    }
}
