<?php

return [
    'required' => ':attribute wajib diisi.',
    'string' => ':attribute harus berupa teks.',
    'email' => 'Format email tidak valid.',
    'max' => ['string' => ':attribute maksimal :max karakter.', 'numeric' => ':attribute maksimal :max.'],
    'min' => ['string' => ':attribute minimal :min karakter.', 'numeric' => ':attribute minimal :min.'],
    'integer' => ':attribute harus berupa bilangan bulat.',
    'enum' => ':attribute tidak dikenal.',
    'regex' => 'Format :attribute tidak valid.',
    'date_format' => ':attribute harus memakai format YYYY-MM-DD yang valid.',
    'before_or_equal' => ':attribute tidak boleh melewati hari ini.',
    'confirmed' => 'Konfirmasi :attribute tidak sesuai.',
    'unique' => ':attribute sudah digunakan.',
    'prohibited' => ':attribute tidak boleh dikirim.',
    'attributes' => [
        'name' => 'Nama', 'email' => 'Email', 'password' => 'Kata sandi',
        'role' => 'Peran', 'is_active' => 'Status akun',
    ],
];
