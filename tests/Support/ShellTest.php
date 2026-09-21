<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\AdminReach;
use Dansk\Support\Shell;
use PHPUnit\Framework\TestCase;

/**
 * Each entry point is its own document and the page is chosen at the door, so an address
 * nobody defined is not a route the browser resolves later — it does not exist.
 *
 * Answering it with a shell makes every mistyped URL a page: a crawler sees a site of
 * unbounded size whose documents are all identical, and a dead link is indistinguishable
 * from a live one because both return 200.
 */
final class ShellTest extends TestCase
{
    public function testTheRootOpensTheApplication(): void
    {
        self::assertSame('/app.html', Shell::forPath('/', false));
    }

    public function testTheReadingAndExamEntryPointsHaveTheirOwnDocuments(): void
    {
        self::assertSame('/read.html', Shell::forPath('/read', false));
        self::assertSame('/proeve.html', Shell::forPath('/proeve', false));
    }

    public function testATrailingSlashIsTheSameAddress(): void
    {
        self::assertSame('/read.html', Shell::forPath('/read/', false));
        self::assertSame('/proeve.html', Shell::forPath('/proeve/', false));
    }

    public function testAnAddressNobodyDefinedDoesNotExist(): void
    {
        self::assertNull(Shell::forPath('/denne-side-findes-ikke', false));
        self::assertNull(Shell::forPath('/secretary/privacy.html', false));
        self::assertNull(Shell::forPath('/sitemap.xml', false));
    }

    /**
     * A prefix is not a namespace. Matching on one turns every address that merely starts
     * with a real path into a page of its own, which is how one entry point becomes an
     * unbounded family of duplicates.
     */
    public function testAPathThatMerelyStartsLikeOneDoesNotBorrowIt(): void
    {
        self::assertNull(Shell::forPath('/reading-list', false));
        self::assertNull(Shell::forPath('/read/2', false));
        self::assertNull(Shell::forPath('/proeven', false));
    }

    public function testTheReviewQueueAnswersThroughTheAdminListener(): void
    {
        self::assertSame('/admin.html', Shell::forPath('/admin', true));
        self::assertSame('/admin.html', Shell::forPath('/admin/queue', true));
    }

    /**
     * Off the admin listener the review queue is absent, which means absent the way any
     * undefined address is — not a shell served under a different name.
     */
    public function testOffThatListenerTheReviewQueueDoesNotExist(): void
    {
        self::assertNull(Shell::forPath('/admin', false));
        self::assertNull(Shell::forPath('/admin/queue', false));
    }

    public function testTheListenerCheckIsTheOneFromAdminReach(): void
    {
        $reachable = AdminReach::reachable([AdminReach::LISTENER => '1']);

        self::assertSame('/admin.html', Shell::forPath('/admin', $reachable));
    }
}
