# Laravel Database Toolkit

![logo with ants-engineers repairing a database](./art/logo-sm.jpg)

[![Latest Stable Version](https://poser.pugx.org/interaction-design-foundation/laravel-db-toolkit/v)](https://packagist.org/packages/interaction-design-foundation/laravel-db-toolkit)
[![Total Downloads](https://poser.pugx.org/interaction-design-foundation/laravel-db-toolkit/downloads)](https://packagist.org/packages/interaction-design-foundation/laravel-db-toolkit)
[![License](https://poser.pugx.org/interaction-design-foundation/laravel-db-toolkit/license)](https://packagist.org/packages/interaction-design-foundation/laravel-db-toolkit)

The package contains few Laravel console commands that validate database schema and data and report about potential issues.


## Installation

You can install the package in to your Laravel app via composer:

```bash
composer require interaction-design-foundation/laravel-db-toolkit
```


## Usage

```shell
# Find invalid data created in non-strict SQL mode.
php artisan database:find-invalid-values

# Find risky auto-incremental columns on databases which values are close to max possible values.
php artisan database:find-risky-columns
```


### Changelog

Please see [Releases](https://github.com/InteractionDesignFoundation/laravel-db-toolkit/releases) for more information on what has changed recently.


## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.


## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
