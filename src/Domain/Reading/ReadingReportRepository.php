<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

use Dansk\Support\Db;
use RuntimeException;

/**
 * The appeal path.
 *
 * Reading options are written by hand, so there is no automatic remedy: a wrong option
 * can only be fixed by editing it. Two independent reports therefore withdraw an item
 * from new rounds and put it in front of a person. That is the opposite of
 * distractor_blocks, whose options are generated, where suppressing one costs nothing and
 * needs no judgement.
 */
final class ReadingReportRepository
{
    /** Two independent readers, because one alone should not be able to bury a question. */
    private const FLAG_AT = 2;

    public function report(
        string $sessionPublicId,
        int $position,
        ?int $userId,
        ?string $anonKey,
        string $reason,
        ?string $note,
    ): void {
        $row = Db::fetchOne(
            'SELECT si.id, si.item_id FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = ?',
            [$sessionPublicId, $position]
        );
        if ($row === null) {
            throw new RuntimeException('No such item in this round.');
        }

        $itemId = (int) $row['item_id'];

        Db::execute('UPDATE reading_session_items SET reported = 1 WHERE id = ?', [(int) $row['id']]);

        // One voice per reader per item: the unique key decides, so a second click by the
        // same person updates their reason rather than counting again.
        $before = (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_reports WHERE item_id = ?', [$itemId]
        );
        Db::execute(
            'INSERT INTO reading_reports (item_id, session_item_id, user_id, anon_key, reason, note)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), note = VALUES(note)',
            [$itemId, (int) $row['id'], $userId, $anonKey, $reason, $note]
        );
        $after = (int) Db::fetchValue(
            'SELECT COUNT(*) FROM reading_reports WHERE item_id = ?', [$itemId]
        );

        if ($after === $before) {
            return;
        }

        Db::execute(
            'UPDATE reading_items SET report_count = ?, is_flagged = ? WHERE id = ?',
            [$after, $after >= self::FLAG_AT ? 1 : 0, $itemId]
        );
    }

    /** @return list<array<string,mixed>> flagged items, each with what was said about it */
    public function flagged(): array
    {
        $items = Db::fetchAll(
            'SELECT i.id, i.position, i.prompt, i.points, i.report_count,
                    p.slug AS passage_slug, p.title AS passage_title, p.kind
             FROM reading_items i
             JOIN reading_passages p ON p.id = i.passage_id
             WHERE i.is_flagged = 1
             ORDER BY i.report_count DESC, i.id'
        );

        foreach ($items as &$item) {
            $item['options'] = Db::fetchAll(
                'SELECT label, text, (id = ?) AS is_correct FROM reading_options
                 WHERE item_id = ? OR (item_id IS NULL AND passage_id =
                       (SELECT passage_id FROM reading_items WHERE id = ?))
                 ORDER BY sort',
                [
                    (int) Db::fetchValue('SELECT correct_option_id FROM reading_items WHERE id = ?', [(int) $item['id']]),
                    (int) $item['id'],
                    (int) $item['id'],
                ]
            );
            $item['reports'] = Db::fetchAll(
                'SELECT reason, note, created_at FROM reading_reports WHERE item_id = ? ORDER BY created_at',
                [(int) $item['id']]
            );
        }

        return $items;
    }

    /** Puts an item back in circulation once a person has judged it sound. */
    public function clear(int $itemId): void
    {
        Db::execute('DELETE FROM reading_reports WHERE item_id = ?', [$itemId]);
        Db::execute('UPDATE reading_items SET is_flagged = 0, report_count = 0 WHERE id = ?', [$itemId]);
    }
}
