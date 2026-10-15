<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$agents = \App\Models\LocalAgent::all();
foreach($agents as $a) {
    echo $a->id . ' ' . $a->public_id . ' ' . $a->status . PHP_EOL;
}