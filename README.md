[](./redis_cli.jpg)

## Introduction

Support redis cluster.

[](./ui.jpg)

Horizon provides a beautiful dashboard and code-driven configuration for your Laravel powered Redis queues. Horizon allows you to easily monitor key metrics of your queue system such as job throughput, runtime, and job failures.

All of your worker configuration is stored in a single, simple configuration file, allowing your configuration to stay in source control where your entire team can collaborate.

## QuickStart

Use Composer

```shell
 composer require helingfeng/horizon v3.8
```

Publish Assets

```shell
php artisan vendor:publish
```

Update Queue.php

```shell
'connection' => 'horizon'
```



## Official Documentation

Documentation for Horizon can be found on the [Laravel website](https://laravel.com/docs/horizon).

## Contributing

Thank you for considering contributing to Horizon! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/horizon/security/policy) on how to report security vulnerabilities.

## License

Laravel Horizon is open-sourced software licensed under the [MIT license](LICENSE.md).
