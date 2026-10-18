<?php
require_once __DIR__ . '/../libs/Alignment.php';
function check(bool $condition, string $name): void { if (!$condition) throw new RuntimeException($name); }
$a = SMLAlignment::append([], 100.0, ['watts' => 3000.0]);
$a = SMLAlignment::append($a, 102.0, ['watts' => 1000.0]);
check(abs(SMLAlignment::at($a, 'watts', 101.0, 3.0) - 2000.0) < 0.001, 'interpolation');
check(SMLAlignment::at($a, 'watts', 103.0, 3.0) === null, 'no extrapolation');
check(SMLAlignment::at($a, 'watts', 101.0, 1.0) === null, 'gap');
echo "Alignment tests OK\n";
