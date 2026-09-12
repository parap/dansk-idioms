<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\AdminReach;
use PHPUnit\Framework\TestCase;

/**
 * The admin surface answers through one listener and is absent through the other.
 *
 * The listener marks itself with a server variable no client can set. A request header
 * of the same name arrives as HTTP_DANSK_ADMIN_LISTENER, which is a different key --
 * without that separation the check would answer to whoever asked it.
 */
final class AdminReachTest extends TestCase
{
    public function testTheAdminListenerIsReachable(): void
    {
        self::assertTrue(AdminReach::reachable([AdminReach::LISTENER => '1']));
    }

    public function testAnyOtherListenerIsNot(): void
    {
        self::assertFalse(AdminReach::reachable([]));
        self::assertFalse(AdminReach::reachable([AdminReach::LISTENER => '0']));
        self::assertFalse(AdminReach::reachable([AdminReach::LISTENER => '']));
    }

    public function testAHeaderOfTheSameNameDoesNotReachIt(): void
    {
        // Apache prefixes request headers with HTTP_, so this is what a client sending
        // "Dansk-Admin-Listener: 1" actually produces.
        self::assertFalse(AdminReach::reachable(['HTTP_DANSK_ADMIN_LISTENER' => '1']));
    }

    public function testTheServerPortIsNotWhatDecides(): void
    {
        // SERVER_PORT comes from the Host header unless UseCanonicalName is on, so it is
        // a value the client picks. Nothing here may depend on it.
        self::assertFalse(AdminReach::reachable(['SERVER_PORT' => '81']));
    }

    public function testTheAdminSurfaceIsRecognisedByHandlerAndByPath(): void
    {
        self::assertTrue(AdminReach::isAdminHandler('admin.queue'));
        self::assertTrue(AdminReach::isAdminHandler('admin.reading.flags'));
        self::assertFalse(AdminReach::isAdminHandler('quiz.start'));

        self::assertTrue(AdminReach::isAdminPath('/admin'));
        self::assertTrue(AdminReach::isAdminPath('/admin.html'));
        self::assertFalse(AdminReach::isAdminPath('/read'));
    }
}
