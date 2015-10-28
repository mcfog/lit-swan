## Swan

PSR-7 style middleware runner for swoole-http

### Install

install swoole extension first

```
pecl install swoole
```
then Swan

```
composer require litphp/lit-swan
```

### Usage

```php
<?php

$app = make_your_psr_middleware();

$server = new SwanServer($app, '127.0.0.1', '9501');
$server->start();
```
