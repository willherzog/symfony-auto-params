# symfony-auto-params
 Provides automatic conversion of config values into container parameters.

 See: https://symfony.com/doc/current/bundles/configuration.html

 Instead of defining a `load()` method in your dependency injection Extension class, make it extend from `WHSymfony\DependencyInjection\AbstractApplicationExtension` and the "loading" will become automatic.
