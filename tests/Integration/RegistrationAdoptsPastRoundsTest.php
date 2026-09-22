<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Domain\UserRepository;
use Dansk\Support\Db;

/**
 * Signing up adopts what was done before it.
 *
 * A round sat without an account is kept against a cookie. If registration does not
 * claim those rounds, the history a learner built up vanishes at the moment they make an
 * account in order to keep it -- and nothing reports the loss, because the rows are
 * still there, merely orphaned.
 */
final class RegistrationAdoptsPastRoundsTest extends IntegrationTestCase
{
    private const ANON = 'abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new ReadingRepository();
        $id   = $repo->save([
            'slug' => 'cykler', 'kind' => 'cloze', 'title' => 'Cykler',
            'body' => 'En tekst med {{1}}.',
            'items' => [
                ['position' => 1, 'options' => [
                    ['label' => 'A', 'text' => 'et', 'correct' => true],
                    ['label' => 'B', 'text' => 'to'],
                    ['label' => 'C', 'text' => 'tre'],
                ]],
            ],
        ]);
        $repo->publish($id);
    }

    public function testARoundSatAsAGuestFollowsTheLearnerOntoTheirNewAccount(): void
    {
        (new ReadingSessionService())->start(null, self::ANON);

        $id = (new UserRepository())->register('ny@example.com', 'et langt kodeord', null, self::ANON);

        self::assertSame(
            1,
            (int) Db::fetchValue('SELECT COUNT(*) FROM reading_sessions WHERE user_id = ?', [$id]),
            'the round sat before signing up did not come along'
        );
    }

    public function testAnotherLearnersRoundsAreNotAdopted(): void
    {
        (new ReadingSessionService())->start(null, str_repeat('f', 32));

        $id = (new UserRepository())->register('ny@example.com', 'et langt kodeord', null, self::ANON);

        self::assertSame(
            0,
            (int) Db::fetchValue('SELECT COUNT(*) FROM reading_sessions WHERE user_id = ?', [$id]),
            'a round belonging to a different browser was claimed'
        );
    }
}
