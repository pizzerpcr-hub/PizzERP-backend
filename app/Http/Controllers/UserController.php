<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre_completo' => 'required|string|max:120',
            'nombre_usuario' => 'required|string|max:50|unique:users,nombre_usuario',
            'contrasena' => [
                'required',
                'string',
                'min:8', 
                'regex:/^(?=(?:.*[A-Za-z]){4,})(?=(?:.*\d){4,}).*$/',
            ],
            'rol' => 'required|string|max:50',
            'estado' => 'boolean',
        ]);


        $usuario = User::create([
            'nombre_completo' => $datos['nombre_completo'],
            'nombre_usuario' => $datos['nombre_usuario'],
            'contrasena' => Hash::make($datos['contrasena']),
            'rol' => $datos['rol'],
            'estado' => $datos['estado'] ?? true,
        ]);

        return response()->json(['message' => 'Usuario creado exitosamente', 'data' => $usuario], 201);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
