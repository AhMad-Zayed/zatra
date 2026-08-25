<?php
$content = file_get_contents('run_architecture_tests.php');
$content = str_replace("'passengers' => [", "'passengersData' => [", $content);
file_put_contents('run_architecture_tests.php', $content);
