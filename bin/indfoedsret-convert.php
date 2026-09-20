<?php declare(strict_types=1);

/**
 * Turns published indfødsretsprøve PDFs into documents under content/indfoedsret/.
 *
 *     php bin/indfoedsret-convert.php <dir>                  every pair in a directory
 *     php bin/indfoedsret-convert.php <paper.pdf> <key.pdf>  one paper
 *
 * A directory is read as pairs: proeve-2026-06.pdf with retteark-2026-06.pdf. A paper
 * whose answer sheet is missing is reported and skipped rather than half-converted.
 *
 * The documents are the source of truth from here on, and bin/reading-import.php loads
 * them like any other authored paper. Converting again overwrites a document, so a
 * correction made to the wording of a question belongs in a commit, not in a file that
 * the next conversion silently replaces -- which is why the command says what it wrote
 * over and what it left alone.
 *
 * Extraction is two different passes on purpose. The questions are read from the laid-out
 * text, which keeps wrapped prompts readable; the answer sheet is read from glyph
 * positions, because some sheets carry a letter that is drawn but never rendered, and
 * only geometry tells it apart from the one a reader sees.
 */

require_once __DIR__ . '/../bootstrap.php';

use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\InvalidPaper;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => !str_starts_with($a, '--')));
$outDir = __DIR__ . '/../content/indfoedsret';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    }
}

if ($args === []) {
    fwrite(STDERR, "usage: php bin/indfoedsret-convert.php <dir> | <paper.pdf> <retteark.pdf> [--out=dir]\n");
    exit(2);
}

if (exec('command -v pdftotext') === '') {
    fwrite(STDERR, "pdftotext is not installed; it comes from poppler-utils.\n");
    exit(2);
}

if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create {$outDir}\n");
    exit(2);
}

/** @return list<array{0:string,1:string}> */
function pairs(array $args): array
{
    if (count($args) === 2 && is_file($args[0]) && is_file($args[1])) {
        return [[$args[0], $args[1]]];
    }

    $dir = $args[0];
    if (!is_dir($dir)) {
        fwrite(STDERR, "no such directory: {$dir}\n");
        exit(2);
    }

    $pairs = [];
    foreach (glob(rtrim($dir, '/') . '/proeve-*.pdf') ?: [] as $paper) {
        $key = str_replace('proeve-', 'retteark-', $paper);
        if (!is_file($key)) {
            printf("  %-28s no answer sheet beside it\n", basename($paper));
            continue;
        }
        $pairs[] = [$paper, $key];
    }
    sort($pairs);

    return $pairs;
}

function pdfText(string $pdf, string $mode): string
{
    $out = tempnam(sys_get_temp_dir(), 'indf');
    $cmd = sprintf('pdftotext %s %s %s 2>/dev/null', $mode, escapeshellarg($pdf), escapeshellarg($out));
    exec($cmd, $ignored, $status);

    $text = (string) file_get_contents($out);
    unlink($out);

    if ($status !== 0 || trim($text) === '') {
        throw new InvalidPaper("pdftotext read nothing from " . basename($pdf) . '.');
    }

    return $text;
}

$written = 0;
$failed  = 0;

foreach (pairs($args) as [$paperPdf, $keyPdf]) {
    $name = basename($paperPdf);

    try {
        $paper = (new PaperParser())->parse(pdfText($paperPdf, '-layout'));
        $key   = (new AnswerKey())->parse(pdfText($keyPdf, '-bbox'));
        $doc   = (new PaperDocument())->render($paper, $key);

        $file     = rtrim($outDir, '/') . '/indfoedsret-' . $paper['date'] . '.txt';
        $existed  = is_file($file);
        $unchanged = $existed && (string) file_get_contents($file) === $doc;

        file_put_contents($file, $doc);
        $written++;

        printf(
            "  %-28s %d questions, pass %s%s%s\n",
            $name,
            count($paper['questions']),
            $key['pass'] === null ? '-' : $key['pass'] . ($key['vaerdier_min'] === null ? '' : '+' . $key['vaerdier_min'] . ' of vaerdier'),
            $key['ignored'] > 0 ? sprintf(', %d unrendered glyph(s) ignored', $key['ignored']) : '',
            $unchanged ? '' : ($existed ? '  [rewritten]' : '  [new]')
        );
    } catch (InvalidPaper $e) {
        printf("  %-28s refused: %s\n", $name, $e->getMessage());
        $failed++;
    }
}

echo "\n";
printf("  %d document(s) written, %d refused\n", $written, $failed);

exit($failed === 0 ? 0 : 1);
