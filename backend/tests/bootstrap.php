<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';

new \think\App(dirname(__DIR__));

require_once dirname(__DIR__) . '/app/common.php';
