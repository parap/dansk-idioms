<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\DistractorService;
use Dansk\Domain\ReviewRepository;
use Dansk\Support\Db;
use InvalidArgumentException;

/**
 * Two idioms that mean the same thing.
 *
 * The distractor picker already refuses to offer a declared synonym, because such an
 * option is a second correct answer rather than a wrong one -- but nothing ever declared
 * any. Sharing a gloss is enough to cause it: once two idioms both mean "расслабиться",
 * either can be served as the other's wrong answer and the learner is marked down for
 * being right.
 */
final class SynonymTest extends IntegrationTestCase
{
    private ReviewRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ReviewRepository();
    }

    private function filler(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->repo->addByHand("at gøre ting nummer {$i}", "делать вещь номер {$i}", [], 'phrase', null, null, 'verbal');
        }
    }

    public function testDeclaringASynonymPutsBothIdiomsInOneGroup(): void
    {
        $a = $this->repo->addByHand('at smide benene op', 'вытянуть ноги');
        $b = $this->repo->addByHand('at skuldrene synker', 'расслабиться', synonyms: ['at smide benene op']);

        $shared = (int) Db::fetchValue(
            'SELECT COUNT(*) FROM idiom_synonyms s1
             JOIN idiom_synonyms s2 ON s2.group_id = s1.group_id
             WHERE s1.idiom_id = ? AND s2.idiom_id = ?',
            [$a, $b]
        );

        self::assertSame(1, $shared);
    }

    public function testASynonymIsNeverOfferedAsAWrongAnswer(): void
    {
        $this->filler(6);
        $a = $this->repo->addByHand('at smide benene op', 'вытянуть ноги', [], 'phrase', null, null, 'verbal');
        $b = $this->repo->addByHand(
            'at skuldrene synker', 'расслабиться', [], 'phrase', null, null, 'verbal',
            synonyms: ['at smide benene op'],
        );

        $correct = Db::fetchOne(
            "SELECT t.id, t.idiom_id, t.text, t.shape, t.word_count, t.char_count, t.sense_type,
                    i.kind, i.register, i.shape AS term_shape
             FROM idiom_translations t JOIN idioms i ON i.id = t.idiom_id
             WHERE t.idiom_id = ? AND t.is_primary = 1", [$a]
        );

        $picker = new DistractorService();
        $offered = 0;
        for ($round = 0; $round < 30; $round++) {
            foreach ($picker->pick($correct, [$a], 'ru', 3) as $option) {
                $offered++;
                self::assertNotSame($b, (int) $option['idiom_id'], 'a synonym was offered as a distractor');
            }
        }

        self::assertGreaterThan(0, $offered, 'no options were offered at all, so nothing was tested');
    }

    public function testASynonymIsNeverOfferedInTheReverseDirectionEither(): void
    {
        $this->filler(6);
        $a = $this->repo->addByHand('at smide benene op', 'вытянуть ноги', [], 'phrase', null, null, 'verbal');
        $b = $this->repo->addByHand(
            'at skuldrene synker', 'расслабиться', [], 'phrase', null, null, 'verbal',
            synonyms: ['at smide benene op'],
        );

        // pickTerms reads term_shape, not shape: the term's own shape and its
        // translation's disagree often enough that the picker keeps them apart.
        $correct = Db::fetchOne(
            'SELECT id AS idiom_id, term, shape AS term_shape, shape, kind, register
             FROM idioms WHERE id = ?', [$a]
        );
        $correct['word_count'] = str_word_count($correct['term']);
        $correct['char_count'] = mb_strlen($correct['term']);

        $picker = new DistractorService();
        $offered = 0;
        for ($round = 0; $round < 30; $round++) {
            foreach ($picker->pickTerms($correct, [$a], 3) as $option) {
                $offered++;
                self::assertNotSame($b, (int) $option['idiom_id'], 'a synonym was offered in reverse');
            }
        }

        self::assertGreaterThan(0, $offered, 'no options were offered at all, so nothing was tested');
    }

    public function testASynonymThatIsNotInTheCorpusIsRefusedByName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at ryste posen');
        $this->repo->addByHand('at smide benene op', 'вытянуть ноги', synonyms: ['at ryste posen']);
    }

    public function testDeclaringTheSameSynonymTwiceDoesNotMakeASecondGroup(): void
    {
        $this->repo->addByHand('at smide benene op', 'вытянуть ноги');
        $this->repo->addByHand('at skuldrene synker', 'расслабиться', synonyms: ['at smide benene op']);
        $this->repo->addByHand('at skuldrene synker', 'расслабиться', synonyms: ['at smide benene op']);

        self::assertSame(1, (int) Db::fetchValue('SELECT COUNT(*) FROM synonym_groups'));
    }

    public function testAnIdiomIsNotItsOwnSynonym(): void
    {
        $this->repo->addByHand('at smide benene op', 'вытянуть ноги', synonyms: ['at smide benene op']);

        self::assertSame(0, (int) Db::fetchValue('SELECT COUNT(*) FROM synonym_groups'));
    }
}
