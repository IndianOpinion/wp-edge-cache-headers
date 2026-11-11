<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders;

/**
 * The resolved configuration for one request: TTLs, lists and switches.
 *
 * Built once per request by the WordPress layer (defaults, network, site,
 * constants, then filters). The decision engine reads it and never changes it.
 */
final class Policy
{
    public const ONE_YEAR = 31536000;
    public const ONE_WEEK = 604800;
    public const DEFAULT_STALE_IF_ERROR = 86400;
    public const DEFAULT_SWR_FACTOR = 5;
    public const DEFAULT_CUSTOM_HEADER = 'X-Edge-Cache';
    public const DEBUG_HEADER = 'X-Edge-Cache-Debug';
    public const DEBUG_TOKEN_HEADER = 'X-Edge-Cache-Debug-Token';

    public const DEFAULT_COOKIE_PREFIXES = [
        'wordpress_logged_in_',
        'wordpress_sec_',
        'wp-postpass_',
        'comment_author_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
        'edd_items_in_cart',
        'wp-resetpass-',
    ];

    public const PREVIEW_QUERY_ARGS = [
        'preview',
        'preview_id',
        'preview_nonce',
        'customize_changeset_uuid',
        'customize_theme',
        'customize_messenger_channel',
        'elementor-preview',
        'elementor_library',
        'fl_builder',
        'et_fb',
        'et_pb_preview',
        'ct_builder',
        'oxygen_iframe',
        'bricks',
        'bricks_preview',
    ];

    public const MODERATION_QUERY_ARGS = ['unapproved', 'moderation-hash'];

    public const NONCE_QUERY_ARGS = ['_wpnonce'];

    public const DEFAULT_PATHS = ['/wp-login.php', '/wp-signup.php', '/wp-activate.php'];

    /** Paths that never reach the main hook; listed so nginx and Cloudflare bypass them too. */
    public const SKIP_PATHS = ['/wp-admin/', '/wp-cron.php', '/xmlrpc.php'];

    public const DEFAULT_SITEMAP_PATTERNS = [
        '/wp-sitemap*.xml',
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/*-sitemap.xml',
        '/*-sitemap[0-9]*.xml',
        '/*_sitemap.xml',
        '/sitemap-*.xml',
        '/sitemap_*.xml',
        '/news-sitemap*.xml',
        '/sitemap-news*.xml',
        '/*.xsl',
    ];

    public const DEFAULT_REST_DENYLIST = [
        '/batch/v1',
        '/wp/v2/users',
        '/wp/v2/settings',
        '/wp/v2/application-passwords',
        '/jwt-auth/',
        '/oauth1',
        '/oauth2',
        '/wp-site-health/',
    ];

    public const DEFAULT_CACHEABLE_STATUSES = [200, 301, 308, 404];

    /** @var array<string, int> */
    public readonly array $ttls;
    /** @var array<string, int> */
    public readonly array $postTypeTtls;
    /** @var list<int> */
    public readonly array $cacheableStatuses;
    /** @var list<string> */
    public readonly array $bypassCookiePrefixes;
    /** @var list<string> */
    public readonly array $bypassQueryArgs;
    /** @var list<string> */
    public readonly array $bypassPaths;
    /** @var list<string> */
    public readonly array $sitemapPatterns;
    /** @var list<string> */
    public readonly array $vary;
    /** @var list<string> */
    public readonly array $restRouteDenylist;
    /** @var list<string> */
    public readonly array $errors;
    /** @var list<string> */
    public readonly array $notices;

    /**
     * @param array<string, int> $ttls Type to TTL map; missing types fall back to the steady profile.
     * @param array<string, int> $postTypeTtls Per post type TTL for `singular_other`.
     * @param list<int> $cacheableStatuses
     * @param list<string> $bypassCookiePrefixes
     * @param list<string> $bypassQueryArgs
     * @param list<string> $bypassPaths
     * @param list<string> $sitemapPatterns
     * @param list<string> $vary
     * @param list<string> $restRouteDenylist
     * @param list<string> $errors Errors met while building the policy (a filter threw).
     * @param list<string> $notices Invalid values that were replaced by defaults.
     */
    public function __construct(
        array $ttls = [],
        array $postTypeTtls = [],
        public readonly bool $enabled = true,
        public readonly int $browserMaxAge = 0,
        public readonly ?int $staleWhileRevalidate = null,
        public readonly int $staleIfError = self::DEFAULT_STALE_IF_ERROR,
        array $cacheableStatuses = self::DEFAULT_CACHEABLE_STATUSES,
        array $bypassCookiePrefixes = self::DEFAULT_COOKIE_PREFIXES,
        ?array $bypassQueryArgs = null,
        array $bypassPaths = self::DEFAULT_PATHS,
        array $sitemapPatterns = self::DEFAULT_SITEMAP_PATTERNS,
        public readonly bool $respectUpstreamPublic = false,
        array $vary = ['Accept-Encoding'],
        public readonly bool $varyIsHonoured = false,
        array $restRouteDenylist = self::DEFAULT_REST_DENYLIST,
        public readonly string $customHeaderName = self::DEFAULT_CUSTOM_HEADER,
        public readonly bool $sendCdnCacheControl = false,
        public readonly bool $sendSurrogateControl = false,
        public readonly bool $sendXAccelExpires = true,
        public readonly bool $sendCustomHeader = true,
        public readonly bool $debug = false,
        public readonly bool $strictMode = false,
        array $errors = [],
        array $notices = [],
    ) {
        $this->ttls = $ttls + Profile::ttls(Profile::STEADY);
        $this->postTypeTtls = $postTypeTtls;
        $this->cacheableStatuses = array_values($cacheableStatuses);
        $this->bypassCookiePrefixes = array_values($bypassCookiePrefixes);
        $this->bypassQueryArgs = array_values($bypassQueryArgs ?? self::defaultQueryArgs());
        $this->bypassPaths = array_values($bypassPaths);
        $this->sitemapPatterns = array_values($sitemapPatterns);
        $this->vary = array_values($vary);
        $this->restRouteDenylist = array_values($restRouteDenylist);
        $this->errors = array_values($errors);
        $this->notices = array_values($notices);
    }

    /**
     * Policy with the built-in defaults for a profile.
     */
    public static function forProfile(string $profile): self
    {
        return new self(ttls: Profile::ttls($profile));
    }

    /**
     * @return list<string>
     */
    public static function defaultQueryArgs(): array
    {
        return array_values(array_merge(
            self::PREVIEW_QUERY_ARGS,
            self::MODERATION_QUERY_ARGS,
            self::NONCE_QUERY_ARGS,
        ));
    }

    /**
     * Copy with some fields replaced.
     *
     * @param array<string, mixed> $changes Constructor argument names to new values.
     */
    public function with(array $changes): self
    {
        $args = get_object_vars($this);
        foreach ($changes as $key => $value) {
            if (!array_key_exists($key, $args)) {
                throw new \InvalidArgumentException(sprintf('Unknown policy field "%s".', $key));
            }
            $args[$key] = $value;
        }
        return new self(...$args);
    }

    /**
     * Header name the nginx snippet reads, lower case with dashes as underscores.
     */
    public function customHeaderVariable(): string
    {
        return str_replace('-', '_', strtolower($this->customHeaderName));
    }
}
