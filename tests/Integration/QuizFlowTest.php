<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\DistractorService;
use Dansk\Domain\QuizService;
use Dansk\Import\Importer;
use Dansk\Support\Db;

final class QuizFlowTest extends IntegrationTestCase
{
    private const ANON = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        (new Importer())->import($this->fixtureExport(), 'Fixture');
    }

    private function quiz(): QuizService
    {
        return new QuizService();
    }

    /** The client is a browser: anything sent to it is visible to the player. */
    public function testTheCorrectAnswerIsNeverSentBeforeTheQuestionIsAnswered(): void
    {
        $session  = $this->quiz()->start(null, self::ANON, 'ru', 5);
        $question = $this->quiz()->question($session['public_id'], 1);

        $payload = json_encode($question, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('correct_index', (string) $payload);
        self::assertStringNotContainsString('correct_tr_id', (string) $payload);

        foreach ($question['options'] as $option) {
            self::assertSame(['index', 'text'], array_keys($option), 'options carry nothing else');
        }
    }

    public function testGradingReadsTheStoredRowNotTheRequest(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 5);
        $stored  = (int) Db::fetchValue(
            'SELECT q.correct_index FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id
             WHERE s.public_id = ? AND q.position = 1',
            [$session['public_id']]
        );

        $wrong  = ($stored + 1) % 4;
        $result = $this->quiz()->answer($session['public_id'], 1, $wrong, 1000);

        self::assertFalse($result['is_correct']);
        self::assertSame($stored, $result['correct_index']);
    }

    public function testAnsweringTwiceIsRefused(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 5);
        $this->quiz()->answer($session['public_id'], 1, 0, 500);

        $this->expectException(\RuntimeException::class);
        $this->quiz()->answer($session['public_id'], 1, 1, 500);
    }

    public function testAFullRoundCanBeCompletedAndScored(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 5);
        $total   = $session['question_count'];
        $correct = 0;

        for ($position = 1; $position <= $total; $position++) {
            $stored = (int) Db::fetchValue(
                'SELECT q.correct_index FROM quiz_questions q
                 JOIN quiz_sessions s ON s.id = q.session_id
                 WHERE s.public_id = ? AND q.position = ?',
                [$session['public_id'], $position]
            );
            $result = $this->quiz()->answer($session['public_id'], $position, $stored, 900);
            self::assertTrue($result['is_correct']);
            $correct++;
        }

        $final = $this->quiz()->result($session['public_id']);
        self::assertSame($correct, $final['correct']);
        self::assertSame($total, $final['total']);
        self::assertCount($total, $final['questions']);
    }

    public function testEveryQuestionHasFourDistinctOptionsFromDistinctIdioms(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 5);

        $rows = Db::fetchAll(
            'SELECT q.options FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id WHERE s.public_id = ?',
            [$session['public_id']]
        );
        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            $options = json_decode((string) $row['options'], true);
            self::assertCount(4, $options);

            $texts = array_column($options, 'text');
            self::assertSame($texts, array_unique($texts), 'no option may be repeated');

            $ids = array_column($options, 'ref');
            $idioms = Db::fetchAll(
                'SELECT DISTINCT idiom_id FROM idiom_translations WHERE id IN ('
                . implode(',', array_map('intval', $ids)) . ')'
            );
            self::assertCount(4, $idioms, 'two options may never come from the same idiom');
        }
    }

    // ---- reverse direction -------------------------------------------------

    public function testAReverseRoundPromptsWithTheMeaningAndOffersDanishOptions(): void
    {
        $session  = $this->quiz()->start(null, self::ANON, 'ru', 5, QuizService::REVERSE);
        $question = $this->quiz()->question($session['public_id'], 1);

        self::assertSame(QuizService::REVERSE, $question['direction']);
        self::assertMatchesRegularExpression('/\p{Cyrillic}/u', $question['prompt']['text'],
            'the prompt is the meaning, so it is Russian');

        foreach ($question['options'] as $option) {
            self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $option['text'],
                'the options are Danish idioms');
        }
    }

    /** In reverse the term is the answer, so it must not appear anywhere in the payload. */
    public function testAReverseQuestionNeverLeaksTheTermOutsideTheOptions(): void
    {
        $session  = $this->quiz()->start(null, self::ANON, 'ru', 5, QuizService::REVERSE);
        $question = $this->quiz()->question($session['public_id'], 1);

        $term = (string) Db::fetchValue(
            'SELECT i.term FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id
             JOIN idioms i ON i.id = q.idiom_id
             WHERE s.public_id = ? AND q.position = 1',
            [$session['public_id']]
        );

        self::assertNotSame('', $term);
        self::assertNull($question['prompt']['note'], 'the note can name the term');
        self::assertNotSame($term, $question['prompt']['text']);

        $optionTexts = array_column($question['options'], 'text');
        self::assertContains($term, $optionTexts, 'the term belongs among the options, and only there');
    }

    public function testReverseDistractorsMatchTheAnswerOnInfinitiveParity(): void
    {
        $rows = Db::fetchAll(
            "SELECT i.id AS idiom_id, i.term, i.term_norm, i.kind, i.register,
                    i.shape AS term_shape
             FROM idioms i WHERE i.is_published = 1"
        );
        $service = new DistractorService();

        foreach ($rows as $correct) {
            foreach ($service->pickTerms($correct, []) as $candidate) {
                self::assertSame(
                    $correct['term_shape'] === 'verbal',
                    $candidate['shape'] === 'verbal',
                    "infinitive parity broken for “{$correct['term']}” against “{$candidate['term']}”"
                );
            }
        }
    }

    public function testAReverseRoundCanBeCompleted(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 4, QuizService::REVERSE);

        for ($position = 1; $position <= $session['question_count']; $position++) {
            $stored = (int) Db::fetchValue(
                'SELECT q.correct_index FROM quiz_questions q
                 JOIN quiz_sessions s ON s.id = q.session_id
                 WHERE s.public_id = ? AND q.position = ?',
                [$session['public_id'], $position]
            );
            $result = $this->quiz()->answer($session['public_id'], $position, $stored, 700);

            self::assertTrue($result['is_correct']);
            // Going back, the answer revealed is the idiom, not the meaning.
            self::assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', (string) $result['correct_text']);
        }

        $final = $this->quiz()->result($session['public_id']);
        self::assertSame($session['question_count'], $final['correct']);
    }

    public function testProgressIsRecordedForASignedInPlayer(): void
    {
        Db::execute("INSERT INTO users (email, password_hash) VALUES ('t@example.com', 'x')");
        $userId = (int) Db::pdo()->lastInsertId();

        $session = $this->quiz()->start($userId, null, 'ru', 3);
        $this->quiz()->answer($session['public_id'], 1, 0, 800);

        $progress = Db::fetchOne('SELECT * FROM user_idiom_progress WHERE user_id = ?', [$userId]);
        self::assertNotNull($progress, 'answering must schedule the idiom for review');
        self::assertNotNull($progress['due_at']);
    }

    public function testResponseTimeFromTheClientIsClamped(): void
    {
        $session = $this->quiz()->start(null, self::ANON, 'ru', 3);
        $this->quiz()->answer($session['public_id'], 1, 0, 999_999_999);

        self::assertLessThanOrEqual(
            300_000,
            (int) Db::fetchValue(
                'SELECT q.response_ms FROM quiz_questions q
                 JOIN quiz_sessions s ON s.id = q.session_id WHERE s.public_id = ? AND q.position = 1',
                [$session['public_id']]
            )
        );
    }

    public function testABlockedDistractorIsNeverOffered(): void
    {
        $correct = Db::fetchOne(
            "SELECT t.id, t.idiom_id, t.text, t.word_count, t.char_count, t.shape, i.register, i.kind
             FROM idiom_translations t JOIN idioms i ON i.id = t.idiom_id
             WHERE i.is_published = 1 AND t.is_primary = 1 LIMIT 1"
        );

        $service = new DistractorService();
        $picked  = $service->pick($correct, []);
        self::assertNotEmpty($picked);

        $blocked = (int) $picked[0]['id'];
        Db::execute(
            "INSERT INTO distractor_blocks (correct_tr_id, blocked_tr_id, reason, is_active)
             VALUES (?, ?, 'reported', 1)",
            [(int) $correct['id'], $blocked]
        );

        for ($i = 0; $i < 25; $i++) {
            foreach ($service->pick($correct, []) as $candidate) {
                self::assertNotSame($blocked, (int) $candidate['id'], 'a reported distractor must never reappear');
            }
        }
    }

    public function testDistractorsMatchTheAnswerOnVerbParity(): void
    {
        $rows = Db::fetchAll(
            "SELECT t.id, t.idiom_id, t.text, t.word_count, t.char_count, t.shape, i.register, i.kind
             FROM idiom_translations t JOIN idioms i ON i.id = t.idiom_id
             WHERE i.is_published = 1 AND t.is_primary = 1"
        );
        $service = new DistractorService();

        foreach ($rows as $correct) {
            foreach ($service->pick($correct, []) as $candidate) {
                self::assertSame(
                    $correct['shape'] === 'verbal',
                    $candidate['shape'] === 'verbal',
                    "verb parity broken for “{$correct['text']}” against “{$candidate['text']}”"
                );
            }
        }
    }
}
