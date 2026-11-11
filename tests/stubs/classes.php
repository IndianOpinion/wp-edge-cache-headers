<?php

// phpcs:ignoreFile -- minimal stand-ins for WordPress and WP-CLI classes.

declare(strict_types=1);

class WP_Query
{
    public bool $is404 = false;
    /** @var list<mixed> */
    public array $posts = [];
    public int $found_posts = 0;

    public function __construct(mixed $args = null)
    {
        if (is_array($args) && isset($args['post_type'])) {
            $this->found_posts = (int) (\IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub::$queryVars['__found_posts'] ?? 0);
        }
    }

    public function is_404(): bool
    {
        return $this->is404;
    }
}

class WP_Post
{
    public string $post_type = 'post';
    public string $post_password = '';
    public string $post_status = 'publish';

    public function __construct(string $type = 'post', string $status = 'publish', string $password = '')
    {
        $this->post_type = $type;
        $this->post_status = $status;
        $this->post_password = $password;
    }
}

class WP_Site
{
    public function __construct(public string $blog_id, public string $domain, public string $path = '/')
    {
    }
}

class WP_HTTP_Response
{
    public function __construct(private int $status = 200)
    {
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

class WP_REST_Request
{
    public function __construct(private string $method = 'GET', private string $route = '/')
    {
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }
}

final class WpCliHalt extends \RuntimeException
{
}

class WP_CLI
{
    public static function line(string $message = ''): void
    {
        \IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub::$output[] = $message;
    }

    public static function success(string $message): void
    {
        self::line('Success: ' . $message);
    }

    public static function warning(string $message): void
    {
        self::line('Warning: ' . $message);
    }

    public static function error(string $message, bool $exit = true): void
    {
        self::line('Error: ' . $message);
        if ($exit) {
            self::halt(1);
        }
    }

    public static function halt(int $code): void
    {
        \IndianOpinion\EdgeCacheHeaders\Tests\Stubs\WpStub::$haltCode = $code;
        throw new WpCliHalt('halt ' . $code);
    }

    public static function add_command(string $name, mixed $callable): void
    {
    }
}
