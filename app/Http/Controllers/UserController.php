<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $authenticatedUser = $request->user();

        if (
            mb_strtoupper($authenticatedUser->rol)
            !== 'ADMINISTRADOR'
        ) {
            return response()->json([
                'message' => 'No tiene permiso para crear usuarios.',
            ], 403);
        }

        $request->merge([
            'nombre_completo' => trim(
                (string) $request->input('nombre_completo')
            ),
            'nombre_usuario' => mb_strtoupper(trim(
                (string) $request->input('nombre_usuario')
            )),
            'rol' => mb_strtoupper(trim(
                (string) $request->input('rol')
            )),
            'estado' => mb_strtoupper(trim(
                (string) $request->input('estado', 'ACTIVO')
            )),
        ]);

        $data = $request->validate([
            'nombre_completo' => [
                'required',
                'string',
                'max:100',
            ],
            'nombre_usuario' => [
                'required',
                'string',
                'max:50',
                'unique:usuarios,nombre_usuario',
            ],
            'contrasena' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],
            'rol' => [
                'required',
                'string',
                'max:30',
            ],
            'estado' => [
                'required',
                'in:ACTIVO,INACTIVO',
            ],
        ], [
            'nombre_completo.required' =>
                'El nombre completo es obligatorio.',
            'nombre_completo.max' =>
                'El nombre completo no puede superar 100 caracteres.',
            'nombre_usuario.required' =>
                'El nombre de usuario es obligatorio.',
            'nombre_usuario.max' =>
                'El nombre de usuario no puede superar 50 caracteres.',
            'nombre_usuario.unique' =>
                'El nombre de usuario ya está registrado.',
            'contrasena.required' =>
                'La contraseña es obligatoria.',
            'contrasena.min' =>
                'La contraseña debe tener al menos 8 caracteres.',
            'rol.required' =>
                'El rol es obligatorio.',
            'rol.max' =>
                'El rol no puede superar 30 caracteres.',
            'estado.in' =>
                'El estado debe ser ACTIVO o INACTIVO.',
        ]);

        $user = User::create([
            'nombre_completo' => $data['nombre_completo'],
            'nombre_usuario' => $data['nombre_usuario'],
            'contrasena_hash' => $data['contrasena'],
            'rol' => $data['rol'],
            'estado' => $data['estado'],
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
        ]);

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'usuario' => [
                'id_usuario' => $user->id_usuario,
                'nombre_completo' => $user->nombre_completo,
                'nombre_usuario' => $user->nombre_usuario,
                'rol' => $user->rol,
                'estado' => $user->estado,
            ],
        ], 201);
    }


    public function index(Request $request): JsonResponse
    {
        $authenticatedUser = $request->user();

        if (
            mb_strtoupper($authenticatedUser->rol)
            !== 'ADMINISTRADOR'
        ) {
            return response()->json([
                'message' => 'No tiene permiso para listar usuarios.',
            ], 403);
        }

        $users = User::all();

        return response()->json([
            'usuarios' => $users->map(function ($user) {
                return [
                    'id_usuario' => $user->id_usuario,
                    'nombre_completo' => $user->nombre_completo,
                    'nombre_usuario' => $user->nombre_usuario,
                    'rol' => $user->rol,
                    'estado' => $user->estado,
                ];
            }),
        ]);
    }




















}