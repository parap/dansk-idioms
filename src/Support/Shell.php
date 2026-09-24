<?php declare(strict_types=1);

namespace Dansk\Support;

/**
 * Decides which shell document answers a path, or that nothing does.
 *
 * The application has no client-side router: every entry point is its own document and
 * the page is chosen here, at the door. An address nobody defined is therefore not a
 * route the browser resolves later — it does not exist, and it answers 404.
 *
 * Serving a shell to it instead makes every mistyped URL a page. A crawler then reads a
 * site of unbounded size whose documents are all identical, and a dead link is
 * indistinguishable from a live one because both return 200 — which is worse than a dead
 * link, because a dead link is visible.
 *
 * Matching is exact, give or take a trailing slash. A prefix is not a namespace: on
 * `str_starts_with` every address that merely begins like a real one borrows its page,
 * so one entry point becomes an unbounded family of duplicates.
 */
final class Shell
{
    /** Entry points, by the address that opens them. */
    private const DOCUMENTS = [
        '/'       => '/app.html',
        '/read'   => '/read.html',
        '/proeve' => '/proeve.html',
        '/proeve/praktisk' => '/praktisk.html',
    ];

    public const ADMIN = '/admin.html';

    public const NOT_FOUND = '/404.html';

    /**
     * The document for $uri, or null when the address does not exist.
     *
     * @param bool $adminReachable whether the admin listener took this request; off it
     *                             the review queue is absent the way any undefined
     *                             address is, not a shell served under another name
     */
    public static function forPath(string $uri, bool $adminReachable): ?string
    {
        if (AdminReach::isAdminPath($uri)) {
            return $adminReachable ? self::ADMIN : null;
        }

        $path = rtrim($uri, '/');

        return self::DOCUMENTS[$path === '' ? '/' : $path] ?? null;
    }
}
