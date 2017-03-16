<?php

require_once __DIR__.'/../vendor/autoload.php';

$settings = new Finvoice\FinvoiceSettings();
$finvoice = new Finvoice\Finvoice($settings);

echo $finvoice;
