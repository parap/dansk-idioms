<?php declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
use Dansk\Import\{HtmlExportReader,EntrySegmenter,EntryParser,Text};
$r=new HtmlExportReader();$s=new EntrySegmenter();$p=new EntryParser();
foreach ($r->read('/var/www/html/storage/exports/messages.html') as $m) {
  if (!str_contains($m['text'],'overblikket') && !str_contains($m['text'],'mærke efter')) continue;
  foreach ($s->segment($m['text'])['entries'] as $e) {
    $x=$p->parse($e);
    if(!preg_match('/overblikket|mærke efter/u',(string)$x->term))continue;
    printf("term     : %s\nstrategy : %s (conf %.2f)\nnote     : %s\n\n",
      $x->term,$x->strategy,$x->confidence,var_export($x->termNote,true));
  }
}
