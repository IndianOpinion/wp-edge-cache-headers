<?php

declare(strict_types=1);

namespace IndianOpinion\EdgeCacheHeaders\WordPress;

/**
 * What the explain tool should simulate.
 */
final class ExplainOptions
{
    /** @var list<string> */
    public readonly array $cookies;
    /** @var array<string, string> */
    public readonly array $headers;
    public readonly string $method;

    /**
     * @param list<string> $cookies Cookie names to pretend the visitor sends.
     * @param array<string, string> $headers Request headers to pretend the visitor sends.
     */
    public function __construct(
        string $method = 'GET',
        array $cookies = [],
        public readonly bool $loggedIn = false,
        array $headers = [],
        public readonly bool $live = false,
    ) {
        if (preg_match('/^[A-Za-z]{1,16}$/', $method) !== 1) {
            throw new ExplainFailed('Method must be a plain HTTP method name, for example GET or POST.');
        }
        $this->method = strtoupper($method);
        foreach ($cookies as $name) {
            if (preg_match('/^[A-Za-z0-9_\-\.\[\]]{1,128}$/', $name) !== 1) {
                throw new ExplainFailed('Cookie names may contain letters, digits, "_", "-", "." and brackets only.');
            }
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9\-]{1,64}$/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new ExplainFailed('Headers must be given as Name:value with a plain header name.');
            }
        }
        $this->cookies = array_values($cookies);
        $this->headers = $headers;
    }

    /**
     * Parse "Name: value" strings.
     *
     * @param list<string> $lines
     * @return array<string, string>
     */
    public static function parseHeaders(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                throw new ExplainFailed(sprintf('Header "%s" is not in Name:value form.', $line));
            }
            $out[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        return $out;
    }
}
