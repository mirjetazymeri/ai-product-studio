<?php

namespace App\Enums;

/**
 * Every way a store request can fail, with text that is safe to show to users.
 */
enum WooCommerceError: string
{
    case InvalidUrl = 'invalid_url';
    case UnsafeAddress = 'unsafe_address';
    case Redirected = 'redirected';
    case InvalidCredentials = 'invalid_credentials';
    case Forbidden = 'forbidden';
    case Timeout = 'timeout';
    case SslError = 'ssl_error';
    case NetworkError = 'network_error';
    case MissingApi = 'missing_api';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case RateLimited = 'rate_limited';
    case Rejected = 'rejected';
    case InvalidResponse = 'invalid_response';
    case Disconnected = 'disconnected';
    case OutcomeUnknown = 'outcome_unknown';

    public static function fromHttpStatus(int $status, string $code = ''): self
    {
        return match (true) {
            $status >= 300 && $status < 400 => self::Redirected,
            $status === 401 => self::InvalidCredentials,
            $status === 403 => self::Forbidden,
            $status === 404 && str_contains($code, 'invalid_id') => self::NotFound,
            $status === 404 => self::MissingApi,
            $status === 429 => self::RateLimited,
            $status >= 500 => self::Unavailable,
            default => self::Rejected,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::InvalidUrl => 'Enter the store\'s full HTTPS address, such as https://shop.example.com.',
            self::UnsafeAddress => 'This address can\'t be used. It must be a public website, not a private or local address.',
            self::Redirected => 'The store redirected the request. Enter the store\'s final HTTPS address, including or excluding "www" as it appears in your browser.',
            self::InvalidCredentials => 'The store rejected the Consumer Key or Consumer Secret. Check them in WooCommerce under Settings, Advanced, REST API.',
            self::Forbidden => 'The store refused access. Check that the key has the right permissions and that no security plugin is blocking the REST API.',
            self::Timeout => 'The store took too long to respond. Please try again.',
            self::SslError => 'The store\'s SSL certificate couldn\'t be verified. It must be valid before the store can be connected.',
            self::NetworkError => 'We couldn\'t reach the store. Check the address and try again.',
            self::MissingApi => 'The WooCommerce REST API wasn\'t found at this address. Check that WooCommerce is active and that WordPress permalinks aren\'t set to "Plain".',
            self::NotFound => 'This product no longer exists in the store.',
            self::Unavailable => 'The store is temporarily unavailable. Please try again shortly.',
            self::RateLimited => 'The store is receiving too many requests. Please wait and try again.',
            self::Rejected => 'The store rejected this request.',
            self::InvalidResponse => 'The store returned a response we couldn\'t read.',
            self::Disconnected => 'This store has been disconnected.',
            self::OutcomeUnknown => 'The store didn\'t confirm this update, so it may or may not have been applied. Retrying checks the store first.',
        };
    }
}
