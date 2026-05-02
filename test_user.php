<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$user = App\Models\User::create([
    'name' => 'Test',
    'email' => 'test_'.time().'@example.com',
    'password' => '123456'
]);

var_dump($user->idUser);
var_dump($user->toArray());
