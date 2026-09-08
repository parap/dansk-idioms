<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingReportRepository;
use Dansk\Http\Response;

/**
 * The reviewer's side of the appeal path. Gated by the admin. handler prefix, like every
 * other admin route.
 */
final class ReadingAdminController
{
    public function __construct(private ReadingReportRepository $reports = new ReadingReportRepository()) {}

    public function flags(): void
    {
        Response::json(['items' => $this->reports->flagged()]);
    }

    public function clear(int $itemId): void
    {
        $this->reports->clear($itemId);
        Response::json(['ok' => true]);
    }
}
