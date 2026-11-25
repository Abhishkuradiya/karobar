<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user registration.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'unique:users,username'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'name'     => ['nullable', 'string', 'max:100'],
            'terms'    => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'name'     => $validated['name'] ?? $validated['username'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status'     => 'success',
            'message'    => 'User registered successfully!',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    /**
     * Handle user login.
     * Supports either email or username.
     */
    public function login(Request $request)
    {
        $request->validate([
            'login'    => ['required', 'string'], // can be email or username
            'password' => ['required', 'string'],
        ]);

        $loginInput = $request->input('login');
        $field = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $user = User::where($field, $loginInput)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid email/username or password.',
            ], 401);
        }

        // Revoke old tokens if desired or issue new token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status'     => 'success',
            'message'    => 'Login successful!',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'Bearer',
        ], 200);
    }

    /**
     * Return authenticated user profile.
     */
    public function user(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'user'   => $request->user(),
        ]);
    }

    /**
     * Handle user logout (revoke current token).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }
}
