<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Container;
use Concursei\Core\Database\Database;
use Concursei\Core\Env;
use Concursei\Core\Http\Middleware\StartSession;
use Concursei\Core\Http\Middleware\Throttle;
use Concursei\Core\Http\Middleware\VerifyCsrf;
use Concursei\Core\Http\Router;
use Concursei\Core\Logging\Logger;
use Concursei\Core\Net\CurlTransport;
use Concursei\Core\Net\DnsResolver;
use Concursei\Core\Net\HttpTransport;
use Concursei\Core\Net\Resolver;
use Concursei\Core\Security\Crypto;
use Concursei\Core\Session\DatabaseSessionHandler;
use Concursei\Core\Session\Session;
use Concursei\Core\View\TemplateHelper;
use Concursei\Core\View\View;

/**
 * Monta a aplicação. $basePath = raiz PRIVADA (fora do webroot em produção).
 * O .env é lido de CONCURSEI_ENV_FILE ou de <basePath>/.env.
 */
return static function (string $basePath, ?Env $env = null): App {
    date_default_timezone_set('UTC');
    mb_internal_encoding('UTF-8');

    $envFile = getenv('CONCURSEI_ENV_FILE') ?: $basePath . '/.env';
    $env ??= Env::fromFile($envFile);
    $config = Config::load($basePath . '/config', $env);

    $c = new Container();
    $c->set(Env::class, $env);
    $c->set(Config::class, $config);
    $c->set(Clock::class, new Clock());

    $c->singleton(Logger::class, static fn () => new Logger($basePath . '/storage/logs', $config->string('app.log_level', 'info')));
    $c->singleton(Database::class, static fn () => new Database([
        'host' => $config->string('database.host'),
        'port' => $config->int('database.port', 3306),
        'database' => $config->string('database.database'),
        'username' => $config->string('database.username'),
        'password' => $config->string('database.password'),
    ]));
    $c->singleton(Crypto::class, static fn () => new Crypto($config->string('app.key')));
    $c->singleton(DatabaseSessionHandler::class, static fn (Container $c) => new DatabaseSessionHandler(
        $c->get(Database::class),
        $c->get(Clock::class),
        $config->int('session.absolute_seconds', 1209600),
    ));
    $c->singleton(Session::class, static fn (Container $c) => new Session(
        $c->get(DatabaseSessionHandler::class),
        $c->get(Clock::class),
        str_starts_with($config->string('app.url'), 'https://'),
        $config->int('session.idle_seconds', 7200),
        $config->int('session.absolute_seconds', 1209600),
    ));
    $c->singleton(Router::class, static fn () => new Router());
    // Rede de saída do coletor (SSRF: ver Core\Net\SafeHttpClient).
    $c->singleton(Resolver::class, static fn () => new DnsResolver());
    $c->singleton(HttpTransport::class, static fn () => new CurlTransport($config->string('collector.user_agent', 'ConcurseiBot/1.0')));
    $c->singleton(View::class, static function (Container $c) use ($basePath, $config): View {
        $view = new View($basePath . '/resources/views');
        $view->share('h', new TemplateHelper($c->get(Session::class), $c->get(Router::class), $config, $basePath . '/public'));
        return $view;
    });

    $app = new App($basePath, $config, $c);
    $c->set(App::class, $app);

    $app->aliasMiddleware('session', StartSession::class);
    $app->aliasMiddleware('csrf', VerifyCsrf::class);
    $app->aliasMiddleware('throttle', Throttle::class);

    // Módulos registram serviços, middlewares e rotas.
    foreach (require $basePath . '/app/modules.php' as $register) {
        $register($app, $c, $config);
    }

    return $app;
};
