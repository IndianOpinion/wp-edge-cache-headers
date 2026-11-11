<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

use IndianOpinion\EdgeCacheHeaders\Decision;
use IndianOpinion\EdgeCacheHeaders\DecisionEngine;
use IndianOpinion\EdgeCacheHeaders\Outcome;
use IndianOpinion\EdgeCacheHeaders\Policy;
use IndianOpinion\EdgeCacheHeaders\RequestFacts;

/**
 * Wires the engine into WordPress.
 *
 * Hook contract (C60 to C63):
 *   - `nocache_headers` (filter, last): observe core's no-cache helper.
 *   - `wp_headers` (filter, 999): main decision, merged into core's header array.
 *   - `send_headers` (action, 999): replace duplicates queued with header(), drop Expires and Pragma.
 *   - `wp_redirect_status` (filter, 999): re-decide for redirects issued later.
 *   - `template_include` (filter, last): late check, downgrade only; strict mode starts here.
 *   - `rest_pre_serve_request` (filter, 999): REST decision.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const LOG_PREFIX = '[wp-edge-cache-headers] ';

    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?Policy $policy = null;
    private ?Decision $decision = null;
    private ?string $ownCacheControl = null;
    private bool $strictStarted = false;
    private bool $strictDone = false;

    /** @var array<string, true> */
    private array $logged = [];

    private readonly DecisionEngine $engine;
    private readonly WordPressHooks $hooks;
    private readonly NocacheObserver $observer;

    public function __construct(
        private readonly HeaderQueue $queue = new PhpHeaderQueue(),
        private readonly ?\Closure $contextResolver = null,
    ) {
        $this->hooks = new WordPressHooks();
        $this->engine = new DecisionEngine($this->hooks);
        $this->observer = new NocacheObserver();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace the shared instance (tests).
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public static function boot(): void
    {
        $plugin = self::instance();
        $plugin->register();
        if (is_admin()) {
            (new Admin\SettingsPage())->register();
        }
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('edge-cache', Cli\Command::class);
        }
    }

    public function register(): void
    {
        add_filter('nocache_headers', [$this->observer, 'observe'], PHP_INT_MAX);
        add_filter('wp_headers', [$this, 'onWpHeaders'], 999);
        add_action('send_headers', [$this, 'onSendHeaders'], 999);
        add_filter('wp_redirect_status', [$this, 'onRedirectStatus'], 999, 2);
        add_filter('template_include', [$this, 'onTemplateInclude'], PHP_INT_MAX);
        add_filter('rest_pre_serve_request', [$this, 'onRestPreServe'], 999, 4);
    }

    public function engine(): DecisionEngine
    {
        return $this->engine;
    }

    public function settings(): Settings
    {
        return $this->settings ??= Settings::load();
    }

    public function policy(): Policy
    {
        return $this->policy ??= (new PolicyFactory($this->hooks))->build($this->settings());
    }

    /**
     * Forget cached settings and the current decision (after a settings save, and in tests).
     */
    public function reset(): void
    {
        $this->settings = null;
        $this->policy = null;
        $this->decision = null;
        $this->ownCacheControl = null;
        $this->strictStarted = false;
        $this->strictDone = false;
        $this->logged = [];
        $this->observer->reset();
    }

    public function currentDecision(): ?Decision
    {
        return $this->decision;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function onWpHeaders($headers)
    {
        if (!is_array($headers) || $this->context() !== RequestFacts::CONTEXT_FRONT) {
            return $headers;
        }
        if ($this->headersAlreadySent()) {
            return $headers;
        }
        $facts = $this->collector()->front($this->policy(), $this->queue, $headers);
        $decision = $this->engine->decide($facts, $this->policy(), 'wp_headers');
        $this->record($decision);
        $this->observer->markDecided();
        return HeaderApplier::toArray($decision, $headers);
    }

    public function onSendHeaders(): void
    {
        if ($this->decision === null || $this->decision->outcome === Outcome::SKIP) {
            return;
        }
        if ($this->headersAlreadySent()) {
            return;
        }
        HeaderApplier::toQueue($this->decision, $this->queue);
    }

    /**
     * @param int|mixed $status
     * @param string|mixed $location
     * @return int|mixed
     */
    public function onRedirectStatus($status, $location = '')
    {
        if (!is_int($status) || $this->context() !== RequestFacts::CONTEXT_FRONT || $this->headersAlreadySent()) {
            return $status;
        }
        $facts = $this->collector()->front($this->policy(), $this->queue, [], $this->ownCacheControl, RequestFacts::CONTEXT_FRONT, $status);
        $decision = $this->engine->decide($facts, $this->policy(), 'wp_redirect_status');
        $previous = $this->ownCacheControl;
        $this->record($decision);
        HeaderApplier::toQueue($decision, $this->queue, $previous);
        return $status;
    }

    /**
     * @param string|mixed $template
     * @return string|mixed
     */
    public function onTemplateInclude($template)
    {
        if ($this->decision === null || $this->context() !== RequestFacts::CONTEXT_FRONT) {
            return $template;
        }
        $this->lateCheck('template_include');
        $decision = $this->decision;
        if ($this->policy()->strictMode && !$this->strictStarted && $decision !== null && $decision->outcome === Outcome::PUBLIC) {
            $this->strictStarted = true;
            ob_start([$this, 'onBufferEnd']);
        }
        return $template;
    }

    /**
     * Strict mode output callback: final late check before the first byte leaves.
     */
    public function onBufferEnd(string $buffer, int $phase = 0): string
    {
        if (!$this->strictDone) {
            $this->strictDone = true;
            try {
                $this->lateCheck('strict-buffer');
            } catch (\Throwable $e) {
                $this->log('strict', 'strict mode check failed: ' . get_class($e));
            }
        }
        return $buffer;
    }

    /**
     * @param bool|mixed $served
     * @param mixed $result
     * @param mixed $request
     * @param mixed $server
     * @return bool|mixed
     */
    public function onRestPreServe($served, $result = null, $request = null, $server = null)
    {
        if (!$request instanceof \WP_REST_Request || !$result instanceof \WP_HTTP_Response) {
            return $served;
        }
        if ($this->headersAlreadySent()) {
            return $served;
        }
        $facts = $this->collector()->rest(
            $this->queue,
            $request->get_method(),
            $request->get_route(),
            $result->get_status(),
        );
        $decision = $this->engine->decide($facts, $this->policy(), 'rest_pre_serve_request');
        $this->record($decision);
        HeaderApplier::toQueue($decision, $this->queue);
        return $served;
    }

    private function lateCheck(string $hook): void
    {
        if ($this->decision === null || $this->decision->outcome !== Outcome::PUBLIC || $this->headersAlreadySent()) {
            return;
        }
        $facts = $this->collector()->front($this->policy(), $this->queue, [], $this->ownCacheControl);
        $late = $this->engine->lateCheck($this->decision, $facts, $this->policy(), $hook);
        if ($late === $this->decision) {
            return;
        }
        $previous = $this->ownCacheControl;
        $this->record($late);
        HeaderApplier::toQueue($late, $this->queue, $previous);
    }

    private function record(Decision $decision): void
    {
        $this->decision = $decision;
        $this->ownCacheControl = $decision->header('Cache-Control');
        if ($decision->outcome !== Outcome::SKIP) {
            try {
                $this->hooks->action('decided', $decision);
            } catch (\Throwable $e) {
                $this->log('decided', 'an edge_cache_headers_decided callback threw ' . get_class($e));
            }
        }
        foreach ($decision->notices as $notice) {
            $this->log('notice:' . $notice, $notice);
        }
    }

    private function headersAlreadySent(): bool
    {
        $file = null;
        $line = null;
        if (!$this->queue->sent($file, $line)) {
            return false;
        }
        $this->log('headers-sent', sprintf('headers already sent (output started at %s:%d); no cache headers added', (string) $file, (int) $line));
        return true;
    }

    /**
     * Write to PHP's error log once per key per request, in debug mode only.
     */
    private function log(string $key, string $message): void
    {
        if (isset($this->logged[$key]) || !$this->policy()->debug) {
            return;
        }
        $this->logged[$key] = true;
        error_log(self::LOG_PREFIX . $message);
    }

    private function context(): string
    {
        if ($this->contextResolver !== null) {
            $context = ($this->contextResolver)();
            return is_string($context) ? $context : RequestFacts::CONTEXT_NONE;
        }
        return FactsCollector::currentContext();
    }

    private function collector(): FactsCollector
    {
        return new FactsCollector($this->settings(), $this->observer);
    }
}
