<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Support\AdminLoginThrottle;
use Dansk\Support\Db;

/**
 * The admin password is a single shared secret on a publicly reachable URL, and the only
 * thing that used to slow a guess down was a 300 ms sleep. That is roughly three attempts
 * a second, indefinitely.
 */
final class AdminLoginThrottleTest extends IntegrationTestCase
{
    private AdminLoginThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new AdminLoginThrottle();
    }

    private function failTimes(string $client, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttle->recordFailure($client);
        }
    }

    public function testAFreshClientMayTry(): void
    {
        self::assertNull($this->throttle->retryAfter('alice'));
    }

    public function testAHandfulOfMistakesIsForgiven(): void
    {
        $this->failTimes('alice', AdminLoginThrottle::MAX_FAILURES - 1);

        self::assertNull($this->throttle->retryAfter('alice'));
    }

    public function testTooManyFailuresLocksTheClientOut(): void
    {
        $this->failTimes('alice', AdminLoginThrottle::MAX_FAILURES);

        $wait = $this->throttle->retryAfter('alice');
        self::assertNotNull($wait);
        self::assertGreaterThan(0, $wait);
        self::assertLessThanOrEqual(AdminLoginThrottle::WINDOW_SECONDS, $wait);
    }

    public function testOneClientLockedOutDoesNotLockOutAnother(): void
    {
        $this->failTimes('alice', AdminLoginThrottle::MAX_FAILURES);

        self::assertNull($this->throttle->retryAfter('bob'));
    }

    public function testTheLockExpiresOnItsOwn(): void
    {
        $this->failTimes('alice', AdminLoginThrottle::MAX_FAILURES);
        Db::execute(
            'UPDATE admin_login_attempts SET window_start = DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [AdminLoginThrottle::WINDOW_SECONDS + 60]
        );

        self::assertNull($this->throttle->retryAfter('alice'));
    }

    public function testSigningInSuccessfullyClearsTheCount(): void
    {
        $this->failTimes('alice', AdminLoginThrottle::MAX_FAILURES - 1);
        $this->throttle->clear('alice');
        $this->failTimes('alice', 1);

        self::assertNull($this->throttle->retryAfter('alice'));
    }

    public function testRotatingTheClientDoesNotBuyUnlimitedGuesses(): void
    {
        // The client comes from a proxy header, so a caller can change it at will. The
        // global backstop is what makes that not a free bypass.
        for ($i = 0; $i < AdminLoginThrottle::GLOBAL_MAX_FAILURES; $i++) {
            $this->throttle->recordFailure('client-' . $i);
        }

        self::assertNotNull($this->throttle->retryAfter('client-never-seen-before'));
    }

    public function testTheGlobalBackstopSitsWellAboveOneClientsLimit(): void
    {
        // Otherwise one clumsy person locks out everybody, including the owner.
        self::assertGreaterThan(
            AdminLoginThrottle::MAX_FAILURES * 3,
            AdminLoginThrottle::GLOBAL_MAX_FAILURES
        );
    }

    public function testTheClientIsNotStoredInReadableForm(): void
    {
        $this->throttle->recordFailure('198.51.100.7');

        self::assertSame(0, (int) Db::fetchValue(
            'SELECT COUNT(*) FROM admin_login_attempts WHERE client LIKE ?', ['%198.51.100.7%']
        ));
        self::assertGreaterThan(0, (int) Db::fetchValue('SELECT COUNT(*) FROM admin_login_attempts'));
    }
}
