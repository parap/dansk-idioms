<?php declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
use Dansk\Import\{EntryParser,TranslationExtractor,Text};
use Dansk\Support\Db;
$parser=new EntryParser(); $tx=new TranslationExtractor();
$rows=Db::fetchAll("SELECT raw_text FROM raw_entries WHERE status='needs_review'");
$n=0;
foreach($rows as $r){
  $p=$parser->parse($r['raw_text']); $t=$tx->extract($p);
  if (array_filter($t,fn($c)=>$c['quiz_usable'])) continue;
  foreach($t as $c) if (str_contains($c['text'],' / ')) {
    if (++$n<=6) printf("  %-28s  words=%d  %s\n", mb_substr((string)$p->term,0,26),
        Text::wordCount($c['text']), mb_substr($c['text'],0,62));
  }
}
printf("  ...%d entries blocked by an unsplit slash phrase\n", $n);
