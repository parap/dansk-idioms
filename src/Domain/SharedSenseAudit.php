<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Support\Db;

/**
 * Finds pairs of published idioms that can be served as each other's wrong answer.
 *
 * The distractor picker excludes options belonging to the idiom being asked about, but
 * not another idiom's copy of the same words. So when two idioms share a sense, that
 * sense can be offered as a wrong option for either of them -- and a learner who picks a
 * translation that is genuinely correct is marked down for it. In reverse rounds it is
 * worse: the options are the Danish terms themselves, and near-duplicate rejection
 * compares text, so two terms that merely mean the same thing sail through.
 *
 * Declaring the pair as synonyms is the fix, which both directions of the picker already
 * honour. This finds the pairs nobody has declared yet.
 */
final class SharedSenseAudit
{
    /**
     * @return list<array{a_id:int,a_term:string,b_id:int,b_term:string,text:string,
     *                    a_primary:bool,b_primary:bool}>
     */
    public function overlaps(): array
    {
        $rows = Db::fetchAll(
            // b.idiom_id > a.idiom_id reports each pair once rather than in both
            // directions. The offerable test mirrors DistractorService::candidatePool:
            // a literal gloss is offered on purpose, so it counts too.
            "SELECT a.idiom_id AS a_id, ia.term AS a_term,
                    b.idiom_id AS b_id, ib.term AS b_term,
                    a.text AS text,
                    a.is_primary AS a_primary, b.is_primary AS b_primary
             FROM idiom_translations a
             JOIN idiom_translations b
               ON b.text_norm = a.text_norm
              AND b.lang_code = a.lang_code
              AND b.idiom_id > a.idiom_id
             JOIN idioms ia ON ia.id = a.idiom_id AND ia.is_published = 1
             JOIN idioms ib ON ib.id = b.idiom_id AND ib.is_published = 1
             WHERE (a.quiz_usable = 1 OR a.sense_type = 'literal')
               AND (b.quiz_usable = 1 OR b.sense_type = 'literal')
               AND NOT EXISTS (
                     SELECT 1 FROM idiom_synonyms s1
                     JOIN idiom_synonyms s2 ON s2.group_id = s1.group_id
                     WHERE s1.idiom_id = a.idiom_id AND s2.idiom_id = b.idiom_id)
             ORDER BY (a.is_primary IS NOT NULL) + (b.is_primary IS NOT NULL) DESC,
                      a.text, a.idiom_id"
        );

        return array_map(
            static fn(array $r): array => [
                'a_id'      => (int) $r['a_id'],
                'a_term'    => (string) $r['a_term'],
                'b_id'      => (int) $r['b_id'],
                'b_term'    => (string) $r['b_term'],
                'text'      => (string) $r['text'],
                'a_primary' => $r['a_primary'] !== null,
                'b_primary' => $r['b_primary'] !== null,
            ],
            $rows
        );
    }
}
